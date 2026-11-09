<?php

namespace App\Modules\Dashboard\Data;

use Illuminate\Support\Facades\DB;

/**
 * Acceso a datos del dashboard de indicadores.
 *
 * La BD de producción es remota y cada round trip cuesta ~200 ms, por lo que
 * el paquete se resuelve en DOS consultas (cada una devuelve una fila con
 * columnas JSON):
 *
 *  1. `get_serie_mensual()` — evolución mes a mes (tn receptadas/despachadas/
 *     compradas/vendidas, montos, costo por elemento y leyes promedio).
 *  2. `get_snapshot()` — fotografía actual: cuentas por pagar/cobrar con su
 *     desglose neto vs detracción, stock en cancha, días promedio en cancha y
 *     valorizaciones aprobadas sin comprobante.
 *
 * UNIDADES (ver `RES_Dashboard` del frontend):
 *  - La BD guarda los pesos en KILOGRAMOS; las toneladas se obtienen con /1000.
 *  - `monto_*_usd` y `*_usd_t` están en dólares.
 *  - `detraccion_pen` está en soles (la detracción se registra en PEN).
 */
class DashboardData
{
    /**
     * Evolución mensual de los indicadores de operación y valorización.
     *
     * @return array<string,array<string,mixed>> Clave `YYYY-MM` => indicadores
     */
    public static function get_serie_mensual(string $fechaInicio, string $fechaFin, ?int $idSucursal): array
    {
        $sql = '
        WITH
        prm AS (
            SELECT ? AS fi, ? AS ff, ? AS suc
        ),
        rec AS (
            SELECT
                DATE_FORMAT(COALESCE(lm.fecha_hora_peso_final, lm.created_at), \'%Y-%m\') AS ym,
                COALESCE(SUM(lm.peso_neto), 0) / 1000 AS tn
            FROM lote_mineral lm
            INNER JOIN recepcion_unidad ru ON ru.id = lm.id_recepcion_unidad
            CROSS JOIN prm p
            WHERE COALESCE(lm.fecha_hora_peso_final, lm.created_at) BETWEEN p.fi AND p.ff
              AND (p.suc IS NULL OR ru.id_sucursal = p.suc)
              AND (lm.estado IS NULL OR lm.estado <> \'Eliminado\')
            GROUP BY ym
        ),
        des AS (
            SELECT
                DATE_FORMAT(d.created_at, \'%Y-%m\') AS ym,
                COALESCE(SUM(dd.peso_tomado), 0) / 1000 AS tn
            FROM despacho d
            INNER JOIN despacho_detalle dd ON dd.id_despacho = d.id
            CROSS JOIN prm p
            WHERE d.created_at BETWEEN p.fi AND p.ff
              AND d.es_anulado = 0
            GROUP BY ym
        ),
        cmp AS (
            SELECT
                DATE_FORMAT(vc.fecha_hora_valorizacion, \'%Y-%m\') AS ym,
                vcd.elemento_quimico AS el,
                COALESCE(SUM(COALESCE(NULLIF(lg.peso_neto, 0), lm.peso_neto, 0)), 0) AS peso,
                COALESCE(SUM(vcd.subtotal), 0) AS monto,
                COALESCE(SUM(vcd.precio_por_tonelada * COALESCE(NULLIF(lg.peso_neto, 0), lm.peso_neto, 0)), 0) AS ptn_pond
            FROM valorizacion_compra vc
            INNER JOIN valorizacion_compramineral_detalle vcd ON vcd.id_valorizacion_compra = vc.id
            LEFT JOIN lote_guia lg ON lg.id = vcd.id_lote_guia
            LEFT JOIN lote_mineral lm ON lm.id = lg.id_lote_mineral
            CROSS JOIN prm p
            WHERE vc.fecha_hora_valorizacion BETWEEN p.fi AND p.ff
              AND vc.estado <> \'Anulado\'
            GROUP BY ym, el
        ),
        vta AS (
            SELECT
                DATE_FORMAT(vv.fecha_hora_valorizacion, \'%Y-%m\') AS ym,
                vvd.elemento_quimico AS el,
                COALESCE(SUM(dd.peso_tomado), 0) AS peso,
                COALESCE(SUM(vvd.subtotal), 0) AS monto,
                COALESCE(SUM(vvd.precio_por_tonelada * dd.peso_tomado), 0) AS ptn_pond
            FROM valorizacion_venta vv
            INNER JOIN valorizacion_venta_detalle vvd ON vvd.id_valorizacion_venta = vv.id
            INNER JOIN despacho_detalle dd ON dd.id = vvd.id_despacho_detalle
            CROSS JOIN prm p
            WHERE vv.fecha_hora_valorizacion BETWEEN p.fi AND p.ff
              AND vv.estado <> \'Anulado\'
            GROUP BY ym, el
        ),
        -- Toneladas COMPRADAS: una vez por LOTE unico valorizado en el mes.
        -- El mismo lote puede aparecer en varias valorizaciones o valorizarse
        -- en Oro y Plata: contar el peso solo una vez evita sumas de mas.
        cmp_lote AS (
            SELECT
                DATE_FORMAT(vc.fecha_hora_valorizacion, \'%Y-%m\') AS ym,
                lm.id AS id_lote,
                COALESCE(MAX(COALESCE(lm.peso_neto, 0)), 0) / 1000 AS tn
            FROM valorizacion_compra vc
            INNER JOIN valorizacion_compramineral_detalle vcd ON vcd.id_valorizacion_compra = vc.id
            LEFT JOIN lote_guia lg ON lg.id = vcd.id_lote_guia
            LEFT JOIN lote_mineral lm ON lm.id = COALESCE(
                lg.id_lote_mineral,
                (SELECT id_lote_mineral FROM particion_lote_mineral WHERE id = lg.id_particion_lote_mineral)
            )
            CROSS JOIN prm p
            WHERE vc.fecha_hora_valorizacion BETWEEN p.fi AND p.ff
              AND vc.estado <> \'Anulado\'
              AND lm.id IS NOT NULL
            GROUP BY ym, lm.id
        ),
        -- Toneladas VENDIDAS: una vez por ITEM DESPACHADO unico valorizado
        -- en el mes (el mismo item puede estar en varias valorizaciones o
        -- valorizarse en Oro y Plata).
        vta_item AS (
            SELECT
                DATE_FORMAT(vv.fecha_hora_valorizacion, \'%Y-%m\') AS ym,
                dd.id AS id_item,
                COALESCE(MAX(COALESCE(dd.peso_tomado, 0)), 0) / 1000 AS tn
            FROM valorizacion_venta vv
            INNER JOIN valorizacion_venta_detalle vvd ON vvd.id_valorizacion_venta = vv.id
            INNER JOIN despacho_detalle dd ON dd.id = vvd.id_despacho_detalle
            CROSS JOIN prm p
            WHERE vv.fecha_hora_valorizacion BETWEEN p.fi AND p.ff
              AND vv.estado <> \'Anulado\'
            GROUP BY ym, dd.id
        ),
        ley_cmp AS (
            SELECT
                DATE_FORMAT(vc.fecha_hora_valorizacion, \'%Y-%m\') AS ym,
                vcd.elemento_quimico AS el,
                COALESCE(SUM(
                    (CASE WHEN vcd.elemento_quimico = \'Oro\'
                          THEN CASE WHEN ga.id IS NOT NULL THEN COALESCE(ga.ley_oro_cierre, 0) ELSE COALESCE(lm.ley_oro, 0) END
                          ELSE CASE WHEN ga.id IS NOT NULL THEN COALESCE(ga.ley_plata_cierre, 0) ELSE COALESCE(lm.ley_plata, 0) END
                     END) * COALESCE(NULLIF(lg.peso_neto, 0), lm.peso_neto, 0)
                ), 0) AS ley_pond,
                COALESCE(SUM(COALESCE(NULLIF(lg.peso_neto, 0), lm.peso_neto, 0)), 0) AS peso
            FROM valorizacion_compra vc
            INNER JOIN valorizacion_compramineral_detalle vcd ON vcd.id_valorizacion_compra = vc.id
            LEFT JOIN lote_guia lg ON lg.id = vcd.id_lote_guia
            LEFT JOIN lote_mineral lm ON lm.id = lg.id_lote_mineral
            LEFT JOIN grupo_analisis ga ON ga.id = vcd.id_grupo_analisis
            CROSS JOIN prm p
            WHERE vc.fecha_hora_valorizacion BETWEEN p.fi AND p.ff
              AND vc.estado <> \'Anulado\'
            GROUP BY ym, el
        ),
        ddt_ley AS (
            SELECT
                id_despacho_detalle,
                AVG(ley_oro_cliente) AS ley_oro,
                AVG(ley_plata_cliente) AS ley_plata
            FROM distribucion_detalle
            GROUP BY id_despacho_detalle
        ),
        ley_vta AS (
            SELECT
                DATE_FORMAT(vv.fecha_hora_valorizacion, \'%Y-%m\') AS ym,
                vvd.elemento_quimico AS el,
                COALESCE(SUM(
                    (CASE WHEN vvd.elemento_quimico = \'Oro\'
                          THEN COALESCE(dd.ley_oro_final, 0)
                          ELSE COALESCE(dd.ley_plata_final, 0)
                     END) * dd.peso_tomado
                ), 0) AS final_pond,
                COALESCE(SUM(
                    (CASE WHEN vvd.elemento_quimico = \'Oro\'
                          THEN COALESCE(dl.ley_oro, 0)
                          ELSE COALESCE(dl.ley_plata, 0)
                     END) * dd.peso_tomado
                ), 0) AS cliente_pond,
                COALESCE(SUM(dd.peso_tomado), 0) AS peso
            FROM valorizacion_venta vv
            INNER JOIN valorizacion_venta_detalle vvd ON vvd.id_valorizacion_venta = vv.id
            INNER JOIN despacho_detalle dd ON dd.id = vvd.id_despacho_detalle
            LEFT JOIN ddt_ley dl ON dl.id_despacho_detalle = dd.id
            CROSS JOIN prm p
            WHERE vv.fecha_hora_valorizacion BETWEEN p.fi AND p.ff
              AND vv.estado <> \'Anulado\'
            GROUP BY ym, el
        )
        SELECT
            (SELECT JSON_ARRAYAGG(JSON_OBJECT(\'ym\', ym, \'tn\', tn)) FROM rec) AS rec,
            (SELECT JSON_ARRAYAGG(JSON_OBJECT(\'ym\', ym, \'tn\', tn)) FROM des) AS des,
            (SELECT JSON_ARRAYAGG(JSON_OBJECT(\'ym\', ym, \'el\', el, \'peso\', peso, \'monto\', monto, \'ptn_pond\', ptn_pond)) FROM cmp) AS cmp,
            (SELECT JSON_ARRAYAGG(JSON_OBJECT(\'ym\', ym, \'el\', el, \'peso\', peso, \'monto\', monto, \'ptn_pond\', ptn_pond)) FROM vta) AS vta,
            (SELECT JSON_ARRAYAGG(JSON_OBJECT(\'ym\', ym, \'tn\', tn)) FROM cmp_lote) AS cmp_lote,
            (SELECT JSON_ARRAYAGG(JSON_OBJECT(\'ym\', ym, \'tn\', tn)) FROM vta_item) AS vta_item,
            (SELECT JSON_ARRAYAGG(JSON_OBJECT(\'ym\', ym, \'el\', el, \'ley_pond\', ley_pond, \'peso\', peso)) FROM ley_cmp) AS ley_cmp,
            (SELECT JSON_ARRAYAGG(JSON_OBJECT(\'ym\', ym, \'el\', el, \'final_pond\', final_pond, \'cliente_pond\', cliente_pond, \'peso\', peso)) FROM ley_vta) AS ley_vta
        ';

        $row = DB::selectOne($sql, [$fechaInicio, $fechaFin, $idSucursal]);

        if (! $row) {
            return [];
        }

        return self::ensamblar_serie($row);
    }

    /**
     * Traducir la fila cruda de la consulta a la serie mensual.
     *
     * @param  object  $row  Fila con las columnas JSON de la consulta.
     * @return array<string,array<string,mixed>> Clave `YYYY-MM` => indicadores
     */
    private static function ensamblar_serie(object $row): array
    {
        $serie = [];

        // Recepción y despacho: una fila por mes.
        foreach (self::json($row->rec ?? null) as $r) {
            $ym = self::ym($r);
            self::mes($serie, $ym);
            $serie[$ym]['tn_receptadas'] = (float) $r['tn'];
        }
        foreach (self::json($row->des ?? null) as $d) {
            $ym = self::ym($d);
            self::mes($serie, $ym);
            $serie[$ym]['tn_despachadas'] = (float) $d['tn'];
        }

        // Compras y ventas: una fila por mes + elemento.
        foreach (self::json($row->cmp ?? null) as $c) {
            $ym = self::ym($c);
            $el = self::el($c);
            if ($el === null) {
                continue;
            }
            $peso = (float) $c['peso'];
            self::mes($serie, $ym);
            $serie[$ym]['costos']['compra'][$el] = $peso > 0 ? (float) $c['ptn_pond'] / $peso : 0.0;
            $serie[$ym]['costos']['compra_total'] += (float) $c['ptn_pond'];
            $serie[$ym]['costos']['compra_peso'] += $peso;
            $serie[$ym]['monto_compra_usd'] += (float) $c['monto'];
        }
        // Toneladas compradas: una vez por LOTE unico (no por elemento ni
        // por valorizacion). Ver CTE `cmp_lote`.
        foreach (self::json($row->cmp_lote ?? null) as $c) {
            $ym = self::ym($c);
            self::mes($serie, $ym);
            $serie[$ym]['tn_compradas'] += (float) $c['tn'];
        }
        foreach (self::json($row->vta ?? null) as $v) {
            $ym = self::ym($v);
            $el = self::el($v);
            if ($el === null) {
                continue;
            }
            $peso = (float) $v['peso'];
            self::mes($serie, $ym);
            $serie[$ym]['costos']['venta'][$el] = $peso > 0 ? (float) $v['ptn_pond'] / $peso : 0.0;
            $serie[$ym]['costos']['venta_total'] += (float) $v['ptn_pond'];
            $serie[$ym]['costos']['venta_peso'] += $peso;
            $serie[$ym]['monto_venta_usd'] += (float) $v['monto'];
        }
        // Toneladas vendidas: una vez por ITEM DESPACHADO unico (no por
        // elemento ni por valorizacion). Ver CTE `vta_item`.
        foreach (self::json($row->vta_item ?? null) as $v) {
            $ym = self::ym($v);
            self::mes($serie, $ym);
            $serie[$ym]['tn_vendidas'] += (float) $v['tn'];
        }

        // Leyes de compra: cierre de leyes del grupo de análisis con fallback
        // a la ley consolidada del lote.
        foreach (self::json($row->ley_cmp ?? null) as $l) {
            $ym = self::ym($l);
            $el = self::el($l);
            if ($el === null) {
                continue;
            }
            $peso = (float) $l['peso'];
            self::mes($serie, $ym);
            $serie[$ym]['leyes']['compra'][$el] = $peso > 0 ? (float) $l['ley_pond'] / $peso : 0.0;
        }

        // Leyes de venta: la "final" es la de Fabero (despacho_detalle) y la
        // "cliente" la que reporta la planta receptora (distribucion_detalle).
        foreach (self::json($row->ley_vta ?? null) as $l) {
            $ym = self::ym($l);
            $el = self::el($l);
            if ($el === null) {
                continue;
            }
            $peso = (float) $l['peso'];
            self::mes($serie, $ym);
            if ($peso > 0) {
                $serie[$ym]['leyes']['venta_final'][$el] = (float) $l['final_pond'] / $peso;
                $serie[$ym]['leyes']['venta_cliente'][$el] = (float) $l['cliente_pond'] / $peso;
            }
        }

        // Cierre: derivar los promedios ponderados de costo.
        foreach ($serie as $ym => $mes) {
            $costos = $mes['costos'];

            $pesoCompra = (float) $costos['compra_peso'];
            $pesoVenta = (float) $costos['venta_peso'];

            $mes['costo_compra_usd_t'] = $pesoCompra > 0 ? (float) $costos['compra_total'] / $pesoCompra : 0.0;
            $mes['costo_venta_usd_t'] = $pesoVenta > 0 ? (float) $costos['venta_total'] / $pesoVenta : 0.0;

            unset($costos['compra_total'], $costos['compra_peso']);
            unset($costos['venta_total'], $costos['venta_peso']);
            $mes['costos'] = $costos;

            $serie[$ym] = $mes;
        }

        return $serie;
    }

    /**
     * Fotografía actual: cuentas por pagar/cobrar, stock y días en cancha,
     * y valorizaciones aprobadas sin comprobante registrado.
     *
     * No lleva filtros de período: son saldos y pendientes acumulados a la
     * fecha de consulta.
     *
     * @return array<string,mixed>
     */
    public static function get_snapshot(): array
    {
        $sql = '
        WITH
        tc AS (
            SELECT fecha, valor_compra, valor_venta
            FROM tipo_cambio
            WHERE estado = \'Activo\'
            ORDER BY fecha DESC
            LIMIT 1
        ),
        cp AS (
            SELECT
                cc.id_valorizacion_compra,
                GREATEST(COALESCE(cc.monto_neto, 0) - COALESCE(cc.avance_pago_neto, 0), 0) AS neto_usd,
                GREATEST(COALESCE(cc.monto_detraccion_soles, 0) - COALESCE(cc.avance_pago_detraccion, 0), 0) AS detraccion_pen
            FROM comprobante_compra cc
            WHERE cc.estado <> \'Anulado\'
        ),
        cv AS (
            SELECT
                cv.id_planta_destino,
                GREATEST(COALESCE(cv.monto_neto, 0) - COALESCE(cv.avance_pago_neto, 0), 0) AS neto_usd,
                GREATEST(COALESCE(cv.monto_detraccion_soles, 0) - COALESCE(cv.avance_pago_detraccion, 0), 0) AS detraccion_pen
            FROM comprobante_venta cv
            WHERE cv.estado <> \'Anulado\'
        ),
        lotes AS (
            -- Peso que queda HOY en cancha de cada lote.
            -- `lote_mineral.peso_actual` ya se descuenta al mezclar (blending) y
            -- al despachar, asi que es el remanente real: no hay que excluir
            -- los lotes que tienen blend o despacho (si se hacia, se perdia del
            -- stock el peso que todavia sigue en la cancha).
            SELECT
                COALESCE(SUM(COALESCE(lm.peso_actual, lm.peso_neto)), 0) / 1000 AS tmh,
                COALESCE(SUM(COALESCE(lm.peso_actual, lm.peso_neto) * (1 - COALESCE(lm.ley_humedad, 0) / 100)), 0) / 1000 AS tms
            FROM lote_mineral lm
            WHERE (lm.estado IS NULL OR lm.estado <> \'Eliminado\')
              AND COALESCE(lm.peso_actual, lm.peso_neto) > 0
        ),
        blends AS (
            SELECT
                COALESCE(SUM(b.peso_actual), 0) / 1000 AS tmh,
                COALESCE(SUM(b.peso_actual * (1 - COALESCE(b.ley_humedad, 0) / 100)), 0) / 1000 AS tms
            FROM blending b
            WHERE b.peso_actual > 0
        ),
        -- Peso de los lotes/ITEMS DESPACHADOS (con despacho no anulado).
        -- `distribucion_detalle.peso_tomado` es la porcion consumida por cada
        -- distribucion: al sumar todas las distribuciones de un
        -- `despacho_detalle` se obtiene el peso total del item despachado
        -- (sin duplicar cuando el item se reparte en varias distribuciones).
        por_salir AS (
            SELECT
                COALESCE(SUM(ddt.peso_tomado), 0) / 1000 AS tmh,
                COALESCE(SUM(ddt.peso_tomado * (1 - COALESCE(
                    CASE WHEN dd.id_lote_mineral IS NOT NULL THEN lm.ley_humedad ELSE b.ley_humedad END, 0) / 100)), 0) / 1000 AS tms
            FROM distribucion_detalle ddt
            INNER JOIN despacho_detalle dd ON dd.id = ddt.id_despacho_detalle
            INNER JOIN despacho d ON d.id = dd.id_despacho AND d.es_anulado = 0
            LEFT JOIN lote_mineral lm ON lm.id = dd.id_lote_mineral
            LEFT JOIN blending b ON b.id = dd.id_blending
        ),
        par_blending AS (
            SELECT
                bd.id_lote_mineral AS id_lote,
                COALESCE(ru.fecha_hora_ingreso, lm.created_at) AS fecha_recepcion,
                MIN(d_b.created_at) AS fecha_despacho
            FROM blending_detalle bd
            INNER JOIN lote_mineral lm ON lm.id = bd.id_lote_mineral
            LEFT JOIN recepcion_unidad ru ON ru.id = lm.id_recepcion_unidad
            LEFT JOIN despacho_detalle dd_b ON dd_b.id_blending = bd.id_blending
            LEFT JOIN despacho d_b ON d_b.id = dd_b.id_despacho AND d_b.es_anulado = 0
            GROUP BY bd.id_lote_mineral, bd.id_blending, ru.fecha_hora_ingreso, lm.created_at
        ),
        par_directo AS (
            SELECT
                dd.id_lote_mineral AS id_lote,
                COALESCE(ru.fecha_hora_ingreso, lm.created_at) AS fecha_recepcion,
                MIN(d.created_at) AS fecha_despacho
            FROM despacho_detalle dd
            INNER JOIN lote_mineral lm ON lm.id = dd.id_lote_mineral
            LEFT JOIN recepcion_unidad ru ON ru.id = lm.id_recepcion_unidad
            INNER JOIN despacho d ON d.id = dd.id_despacho AND d.es_anulado = 0
            GROUP BY dd.id_lote_mineral, ru.fecha_hora_ingreso, lm.created_at
        ),
        ciclos AS (
            SELECT fecha_recepcion, fecha_despacho, \'blending\' AS tipo
            FROM par_blending
            WHERE fecha_despacho IS NOT NULL
            UNION ALL
            SELECT fecha_recepcion, fecha_despacho, \'directo\' AS tipo
            FROM par_directo
            WHERE fecha_despacho IS NOT NULL
        ),
        sin_comp_compras AS (
            SELECT
                vc.correlativo AS documento,
                p.razon_social AS contraparte,
                vc.fecha_hora_valorizacion AS fecha,
                COALESCE((SELECT SUM(vcd.subtotal) FROM valorizacion_compramineral_detalle vcd WHERE vcd.id_valorizacion_compra = vc.id), 0) AS monto
            FROM valorizacion_compra vc
            INNER JOIN proveedor p ON p.id = vc.id_proveedor_minero
            WHERE vc.estado = \'Aprobado\'
              AND NOT EXISTS (SELECT 1 FROM comprobante_compra cc2 WHERE cc2.id_valorizacion_compra = vc.id)
        ),
        sin_comp_ventas AS (
            SELECT
                vv.correlativo AS documento,
                pd.razon_social AS contraparte,
                vv.fecha_hora_valorizacion AS fecha,
                COALESCE((SELECT SUM(vvd.subtotal) FROM valorizacion_venta_detalle vvd WHERE vvd.id_valorizacion_venta = vv.id), 0) AS monto
            FROM valorizacion_venta vv
            INNER JOIN planta_destino pd ON pd.id = vv.id_planta
            WHERE vv.estado = \'Aprobado\'
              AND EXISTS (
                  SELECT 1 FROM valorizacion_venta_detalle vvd2
                  WHERE vvd2.id_valorizacion_venta = vv.id
                    AND vvd2.id NOT IN (
                        SELECT dcv.id_valorizacion_venta_detalle
                        FROM detalle_comprobante_venta dcv
                        INNER JOIN comprobante_venta cv2 ON cv2.id = dcv.id_comprobante_venta
                        WHERE cv2.estado <> \'Anulado\'
                    )
              )
        )
        SELECT
            (SELECT JSON_OBJECT(
                \'neto_usd\', (SELECT COALESCE(SUM(neto_usd), 0) FROM cp),
                \'detraccion_pen\', (SELECT COALESCE(SUM(detraccion_pen), 0) FROM cp),
                \'mayor_deudor\', (SELECT JSON_OBJECT(\'nombre\', x.nombre, \'monto_usd\', x.saldo)
                    FROM (
                        SELECT p.razon_social AS nombre, SUM(cp.neto_usd) AS saldo
                        FROM cp
                        INNER JOIN valorizacion_compra vc ON vc.id = cp.id_valorizacion_compra
                        INNER JOIN proveedor p ON p.id = vc.id_proveedor_minero
                        GROUP BY p.id, p.razon_social
                        HAVING saldo > 0
                        ORDER BY saldo DESC
                        LIMIT 1
                    ) x
                )
            )) AS por_pagar,
            (SELECT JSON_OBJECT(
                \'neto_usd\', (SELECT COALESCE(SUM(neto_usd), 0) FROM cv),
                \'detraccion_pen\', (SELECT COALESCE(SUM(detraccion_pen), 0) FROM cv),
                \'mayor_acreedor\', (SELECT JSON_OBJECT(\'nombre\', x.nombre, \'monto_usd\', x.saldo)
                    FROM (
                        SELECT pd.razon_social AS nombre, SUM(cv.neto_usd) AS saldo
                        FROM cv
                        INNER JOIN planta_destino pd ON pd.id = cv.id_planta_destino
                        GROUP BY pd.id, pd.razon_social
                        HAVING saldo > 0
                        ORDER BY saldo DESC
                        LIMIT 1
                    ) x
                )
            )) AS por_cobrar,
            (SELECT JSON_OBJECT(\'fecha\', tc.fecha, \'valor_compra\', tc.valor_compra, \'valor_venta\', tc.valor_venta) FROM tc) AS tipo_cambio,
            (SELECT JSON_OBJECT(
                \'lotes\', (SELECT JSON_OBJECT(\'tmh\', lotes.tmh, \'tms\', lotes.tms) FROM lotes),
                \'blendings\', (SELECT JSON_OBJECT(\'tmh\', blends.tmh, \'tms\', blends.tms) FROM blends),
                \'por_salir\', (SELECT JSON_OBJECT(\'tmh\', ps.tmh, \'tms\', ps.tms) FROM por_salir ps)
            )) AS stock,
            (SELECT JSON_OBJECT(
                \'todo\', (SELECT COALESCE(ROUND(AVG(DATEDIFF(fecha_despacho, fecha_recepcion)), 2), 0) FROM ciclos),
                \'blending\', (SELECT COALESCE(ROUND(AVG(DATEDIFF(fecha_despacho, fecha_recepcion)), 2), 0) FROM ciclos WHERE tipo = \'blending\'),
                \'directo\', (SELECT COALESCE(ROUND(AVG(DATEDIFF(fecha_despacho, fecha_recepcion)), 2), 0) FROM ciclos WHERE tipo = \'directo\')
            )) AS dias,
            (SELECT JSON_OBJECT(
                \'compras\', (SELECT JSON_OBJECT(\'conteo\', x.conteo, \'monto_usd\', x.monto) FROM (
                    SELECT COUNT(*) AS conteo, COALESCE(SUM(monto), 0) AS monto FROM sin_comp_compras
                ) x),
                \'ventas\', (SELECT JSON_OBJECT(\'conteo\', x.conteo, \'monto_usd\', x.monto) FROM (
                    SELECT COUNT(*) AS conteo, COALESCE(SUM(monto), 0) AS monto FROM sin_comp_ventas
                ) x),
                \'detalle\', (SELECT JSON_ARRAYAGG(JSON_OBJECT(
                    \'tipo\', y.tipo, \'documento\', y.documento, \'contraparte\', y.contraparte,
                    \'dias\', y.dias, \'monto_usd\', y.monto
                )) FROM (
                    SELECT \'C\' AS tipo, documento, contraparte, fecha, DATEDIFF(CURRENT_DATE, DATE(fecha)) AS dias, monto
                    FROM sin_comp_compras
                    UNION ALL
                    SELECT \'V\' AS tipo, documento, contraparte, fecha, DATEDIFF(CURRENT_DATE, DATE(fecha)) AS dias, monto
                    FROM sin_comp_ventas
                    ORDER BY dias DESC
                    LIMIT 12
                ) y)
            )) AS sin_comprobante
        ';

        $row = DB::selectOne($sql);

        if (! $row) {
            return [];
        }

        return [
            'por_pagar' => self::json($row->por_pagar ?? null),
            'por_cobrar' => self::json($row->por_cobrar ?? null),
            'tipo_cambio' => self::json($row->tipo_cambio ?? null),
            'stock' => self::json($row->stock ?? null),
            'dias' => self::json($row->dias ?? null),
            'sin_comprobante' => self::json($row->sin_comprobante ?? null),
        ];
    }

    /**
     * Decodificar una columna JSON de MySQL.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function json(mixed $valor): array
    {
        if ($valor === null || $valor === '') {
            return [];
        }

        $arr = json_decode((string) $valor, true);

        return is_array($arr) ? $arr : [];
    }

    /**
     * Inicializar la estructura de un mes dentro de la serie.
     *
     * Todos los acumuladores se crean con 0 para que los `+=` posteriores no
     * operen sobre claves inexistentes (PHP 8 lanza "Undefined array key").
     *
     * @param  array<string,array<string,mixed>>  $serie
     */
    private static function mes(array &$serie, string $ym): void
    {
        if (isset($serie[$ym])) {
            return;
        }

        $serie[$ym] = [
            'tn_receptadas' => 0.0,
            'tn_despachadas' => 0.0,
            'tn_compradas' => 0.0,
            'tn_vendidas' => 0.0,
            'monto_compra_usd' => 0.0,
            'monto_venta_usd' => 0.0,
            'costos' => [
                'compra' => ['Oro' => 0.0, 'Plata' => 0.0],
                'venta' => ['Oro' => 0.0, 'Plata' => 0.0],
                'compra_total' => 0.0,
                'compra_peso' => 0.0,
                'venta_total' => 0.0,
                'venta_peso' => 0.0,
            ],
            'leyes' => [
                'compra' => ['Oro' => 0.0, 'Plata' => 0.0],
                'venta_final' => ['Oro' => 0.0, 'Plata' => 0.0],
                'venta_cliente' => ['Oro' => 0.0, 'Plata' => 0.0],
            ],
        ];
    }

    /**
     * Clave de mes `YYYY-MM` de una fila agregada.
     */
    private static function ym(array $fila): string
    {
        return (string) ($fila['ym'] ?? '');
    }

    /**
     * Elemento químico normalizado (`Oro` / `Plata`).
     * Devuelve null cuando la fila no corresponde a un elemento conocido.
     */
    private static function el(array $fila): ?string
    {
        $el = (string) ($fila['el'] ?? '');

        return match ($el) {
            'Oro' => 'Oro',
            'Plata' => 'Plata',
            default => null,
        };
    }
}
