<?php

namespace App\Modules\Dashboard\Data;

use Illuminate\Support\Facades\DB;

/**
 * Acceso a datos del dashboard de indicadores.
 *
 * La BD de producción es remota y cada round trip cuesta ~200 ms, por lo que
 * todo el paquete de indicadores se resuelve en UNA sola consulta que devuelve
 * una fila con columnas JSON. Los filtros de período y sucursal viajan en el
 * CTE `prm` para no repetir parámetros.
 */
class DashboardData
{
    /**
     * Obtener el paquete completo de indicadores del dashboard.
     *
     * @return array<string,mixed>
     */
    public static function get_dashboard(?int $idSucursal, string $fechaInicio, string $fechaFin): array
    {
        $sql = self::sql_ctes().self::sql_salida();

        $row = DB::selectOne($sql, [$fechaInicio, $fechaFin, $idSucursal]);

        return self::decodificar($row);
    }

    /**
     * CTEs compartidos: filtros, grano diario y totales agregados.
     */
    private static function sql_ctes(): string
    {
        return '
WITH
prm AS (
    SELECT ? AS fi, ? AS ff, ? AS suc
),
dias AS (
    SELECT DATE(COALESCE(lm.fecha_hora_peso_final, lm.created_at)) AS dia
    FROM lote_mineral lm
    INNER JOIN recepcion_unidad ru ON ru.id = lm.id_recepcion_unidad
    CROSS JOIN prm p
    WHERE COALESCE(lm.fecha_hora_peso_final, lm.created_at) BETWEEN p.fi AND p.ff
      AND (p.suc IS NULL OR ru.id_sucursal = p.suc)
    UNION
    SELECT DATE(vc.fecha_hora_valorizacion) AS dia
    FROM valorizacion_compra vc
    CROSS JOIN prm p
    WHERE vc.fecha_hora_valorizacion BETWEEN p.fi AND p.ff
      AND vc.estado <> \'Anulado\'
    UNION
    SELECT DATE(vv.fecha_hora_valorizacion) AS dia
    FROM valorizacion_venta vv
    CROSS JOIN prm p
    WHERE vv.fecha_hora_valorizacion BETWEEN p.fi AND p.ff
      AND vv.estado <> \'Anulado\'
    UNION
    SELECT DATE(d.created_at) AS dia
    FROM despacho d
    CROSS JOIN prm p
    WHERE d.created_at BETWEEN p.fi AND p.ff
      AND d.es_anulado = 0
),
lote_confirmacion AS (
    SELECT
        ga.id_lote_mineral,
        MAX(am.created_at) AS confirmado_at
    FROM analisis_mineral am
    INNER JOIN grupo_analisis ga ON ga.id = am.id_grupo_analisis
    WHERE (am.ley_oro_confirmada = 1 OR am.ley_plata_confirmada = 1)
      AND ga.id_lote_mineral IS NOT NULL
    GROUP BY ga.id_lote_mineral
),
lote_aprobado AS (
    SELECT DISTINCT COALESCE(lg.id_lote_mineral, plm.id_lote_mineral) AS id_lote_mineral
    FROM valorizacion_compramineral_detalle vcd
    INNER JOIN valorizacion_compra vc ON vc.id = vcd.id_valorizacion_compra
    INNER JOIN lote_guia lg ON lg.id = vcd.id_lote_guia
    LEFT JOIN particion_lote_mineral plm ON plm.id = lg.id_particion_lote_mineral
    WHERE vc.estado = \'Aprobado\'
      AND COALESCE(lg.id_lote_mineral, plm.id_lote_mineral) IS NOT NULL
),
rec AS (
    SELECT
        DATE(COALESCE(lm.fecha_hora_peso_final, lm.created_at)) AS dia,
        COUNT(*) AS lotes,
        COALESCE(SUM(lm.peso_neto), 0) / 1000 AS toneladas,
        COALESCE(SUM(lm.peso_neto), 0) AS peso_total,
        COALESCE(SUM(CASE WHEN la.id_lote_mineral IS NOT NULL AND lm.ley_oro > 0 THEN lm.ley_oro * lm.peso_neto ELSE 0 END), 0) AS oro_pond,
        COALESCE(SUM(CASE WHEN la.id_lote_mineral IS NOT NULL AND lm.ley_oro > 0 THEN lm.peso_neto ELSE 0 END), 0) AS peso_ley_oro,
        COALESCE(SUM(CASE WHEN la.id_lote_mineral IS NOT NULL AND lm.ley_plata > 0 THEN lm.ley_plata * lm.peso_neto ELSE 0 END), 0) AS plata_pond,
        COALESCE(SUM(CASE WHEN la.id_lote_mineral IS NOT NULL AND lm.ley_plata > 0 THEN lm.peso_neto ELSE 0 END), 0) AS peso_ley_plata,
        COALESCE(SUM(CASE WHEN la.id_lote_mineral IS NOT NULL AND lm.ley_humedad > 0 THEN lm.ley_humedad * lm.peso_neto ELSE 0 END), 0) AS hum_pond,
        COALESCE(SUM(CASE WHEN la.id_lote_mineral IS NOT NULL AND lm.ley_humedad > 0 THEN lm.peso_neto ELSE 0 END), 0) AS peso_ley_humedad,
        SUM(CASE WHEN la.id_lote_mineral IS NOT NULL THEN 1 ELSE 0 END) AS lotes_aprobados,
        SUM(CASE WHEN lm.esta_validado = 1 THEN 1 ELSE 0 END) AS validados,
        SUM(CASE WHEN lc.id_lote_mineral IS NOT NULL THEN 1 ELSE 0 END) AS analizados,
        SUM(CASE WHEN COALESCE(lm.con_valor_comercial, 0) = 1 THEN 1 ELSE 0 END) AS con_valor,
        SUM(CASE WHEN lm.esta_valorizado_oro = 1 OR lm.esta_valorizado_plata = 1 THEN 1 ELSE 0 END) AS valorizados,
        SUM(CASE WHEN EXISTS (SELECT 1 FROM grupo_analisis g WHERE g.id_lote_mineral = lm.id) THEN 1 ELSE 0 END) AS con_cierre_leyes,
        SUM(CASE WHEN EXISTS (SELECT 1 FROM despacho_detalle dd WHERE dd.id_lote_mineral = lm.id) THEN 1 ELSE 0 END) AS despachados
    FROM lote_mineral lm
    INNER JOIN recepcion_unidad ru ON ru.id = lm.id_recepcion_unidad
    LEFT JOIN lote_aprobado la ON la.id_lote_mineral = lm.id
    LEFT JOIN lote_confirmacion lc ON lc.id_lote_mineral = lm.id
    CROSS JOIN prm p
    WHERE COALESCE(lm.fecha_hora_peso_final, lm.created_at) BETWEEN p.fi AND p.ff
      AND (p.suc IS NULL OR ru.id_sucursal = p.suc)
    GROUP BY dia
),
val AS (
    SELECT
        DATE(vc.fecha_hora_valorizacion) AS dia,
        COALESCE(SUM(vcd.subtotal), 0) AS valor_usd,
        COALESCE(SUM(vcd.precio_por_tonelada * COALESCE(NULLIF(lg.peso_neto, 0), lm.peso_neto, 0)), 0) AS ptn_ponderado,
        COALESCE(SUM(COALESCE(NULLIF(lg.peso_neto, 0), lm.peso_neto, 0)), 0) AS peso
    FROM valorizacion_compra vc
    INNER JOIN valorizacion_compramineral_detalle vcd ON vcd.id_valorizacion_compra = vc.id
    LEFT JOIN lote_guia lg ON lg.id = vcd.id_lote_guia
    LEFT JOIN lote_mineral lm ON lm.id = lg.id_lote_mineral
    CROSS JOIN prm p
    WHERE vc.fecha_hora_valorizacion BETWEEN p.fi AND p.ff
      AND vc.estado <> \'Anulado\'
    GROUP BY dia
),
vta AS (
    SELECT
        DATE(vv.fecha_hora_valorizacion) AS dia,
        COALESCE(SUM(vvd.subtotal), 0) AS valor_usd
    FROM valorizacion_venta vv
    INNER JOIN valorizacion_venta_detalle vvd ON vvd.id_valorizacion_venta = vv.id
    CROSS JOIN prm p
    WHERE vv.fecha_hora_valorizacion BETWEEN p.fi AND p.ff
      AND vv.estado <> \'Anulado\'
    GROUP BY dia
),
des AS (
    SELECT
        DATE(d.created_at) AS dia,
        COALESCE(SUM(dd.peso_tomado), 0) / 1000 AS toneladas,
        COUNT(DISTINCT d.id) AS despachos
    FROM despacho d
    INNER JOIN despacho_detalle dd ON dd.id_despacho = d.id
    CROSS JOIN prm p
    WHERE d.created_at BETWEEN p.fi AND p.ff
      AND d.es_anulado = 0
    GROUP BY dia
),
serie AS (
    SELECT
        d.dia,
        COALESCE(r.lotes, 0) AS lotes,
        COALESCE(r.toneladas, 0) AS toneladas,
        COALESCE(r.oro_pond, 0) AS oro_pond,
        COALESCE(r.peso_ley_oro, 0) AS peso_ley_oro,
        COALESCE(r.plata_pond, 0) AS plata_pond,
        COALESCE(r.peso_ley_plata, 0) AS peso_ley_plata,
        COALESCE(r.hum_pond, 0) AS hum_pond,
        COALESCE(r.peso_ley_humedad, 0) AS peso_ley_humedad,
        COALESCE(r.lotes_aprobados, 0) AS lotes_aprobados,
        COALESCE(r.validados, 0) AS validados,
        COALESCE(r.analizados, 0) AS analizados,
        COALESCE(r.con_cierre_leyes, 0) AS con_cierre_leyes,
        COALESCE(r.con_valor, 0) AS con_valor,
        COALESCE(r.valorizados, 0) AS valorizados,
        COALESCE(r.despachados, 0) AS despachados,
        COALESCE(v.valor_usd, 0) AS valor_compra_usd,
        COALESCE(v.ptn_ponderado, 0) AS ptn_ponderado,
        COALESCE(v.peso, 0) AS peso_valorizado,
        COALESCE(vt.valor_usd, 0) AS valor_venta_usd,
        COALESCE(ds.toneladas, 0) AS toneladas_despachadas,
        COALESCE(ds.despachos, 0) AS despachos
    FROM dias d
    LEFT JOIN rec r ON r.dia = d.dia
    LEFT JOIN val v ON v.dia = d.dia
    LEFT JOIN vta vt ON vt.dia = d.dia
    LEFT JOIN des ds ON ds.dia = d.dia
),
tot AS (
    SELECT
        COALESCE(SUM(toneladas), 0) AS toneladas,
        COALESCE(SUM(lotes), 0) AS lotes,
        COALESCE(SUM(oro_pond), 0) AS oro_pond,
        COALESCE(SUM(peso_ley_oro), 0) AS peso_ley_oro,
        COALESCE(SUM(plata_pond), 0) AS plata_pond,
        COALESCE(SUM(peso_ley_plata), 0) AS peso_ley_plata,
        COALESCE(SUM(hum_pond), 0) AS hum_pond,
        COALESCE(SUM(peso_ley_humedad), 0) AS peso_ley_humedad,
        COALESCE(SUM(lotes_aprobados), 0) AS lotes_aprobados,
        COALESCE(SUM(validados), 0) AS validados,
        COALESCE(SUM(analizados), 0) AS analizados,
        COALESCE(SUM(con_cierre_leyes), 0) AS con_cierre_leyes,
        COALESCE(SUM(con_valor), 0) AS con_valor,
        COALESCE(SUM(valorizados), 0) AS valorizados,
        COALESCE(SUM(despachados), 0) AS despachados,
        COALESCE(SUM(valor_compra_usd), 0) AS valor_compra_usd,
        COALESCE(SUM(ptn_ponderado), 0) AS ptn_ponderado,
        COALESCE(SUM(peso_valorizado), 0) AS peso_valorizado,
        COALESCE(SUM(valor_venta_usd), 0) AS valor_venta_usd,
        COALESCE(SUM(toneladas_despachadas), 0) AS toneladas_despachadas,
        COALESCE(SUM(despachos), 0) AS despachos
    FROM serie
),
emb AS (
    SELECT 1 AS orden, \'Lotes generados\' AS etapa, COALESCE(SUM(lotes), 0) AS conteo FROM tot
    UNION ALL SELECT 2, \'Validados\', validados FROM tot
    UNION ALL SELECT 3, \'Análisis confirmado\', analizados FROM tot
    UNION ALL SELECT 4, \'Cierre de leyes\', con_cierre_leyes FROM tot
    UNION ALL SELECT 5, \'Con valor comercial\', con_valor FROM tot
    UNION ALL SELECT 6, \'Propuesta de valorización\', valorizados FROM tot
    UNION ALL SELECT 7, \'Valorización aprobada\', lotes_aprobados FROM tot
    UNION ALL SELECT 8, \'Despachado\', despachados FROM tot
)
';
    }

    /**
     * SELECT principal: una fila con columnas JSON.
     */
    private static function sql_salida(): string
    {
        return '
SELECT
    (SELECT JSON_ARRAYAGG(JSON_OBJECT(
        \'fecha\', s.dia,
        \'toneladas\', s.toneladas,
        \'lotes\', s.lotes,
        \'ley_oro\', ROUND(CASE WHEN s.peso_ley_oro > 0 THEN s.oro_pond / s.peso_ley_oro ELSE 0 END, 4),
        \'ley_plata\', ROUND(CASE WHEN s.peso_ley_plata > 0 THEN s.plata_pond / s.peso_ley_plata ELSE 0 END, 4),
        \'ley_humedad\', ROUND(CASE WHEN s.peso_ley_humedad > 0 THEN s.hum_pond / s.peso_ley_humedad ELSE 0 END, 4),
        \'valor_compra_usd\', s.valor_compra_usd,
        \'valor_venta_usd\', s.valor_venta_usd,
        \'toneladas_despachadas\', s.toneladas_despachadas
    )) FROM serie s) AS serie,

    JSON_OBJECT(
        \'toneladas_receptadas\', tot.toneladas,
        \'lotes_receptados\', tot.lotes,
        \'ley_oro_prom\', ROUND(CASE WHEN tot.peso_ley_oro > 0 THEN tot.oro_pond / tot.peso_ley_oro ELSE 0 END, 4),
        \'ley_plata_prom\', ROUND(CASE WHEN tot.peso_ley_plata > 0 THEN tot.plata_pond / tot.peso_ley_plata ELSE 0 END, 4),
        \'ley_humedad_prom\', ROUND(CASE WHEN tot.peso_ley_humedad > 0 THEN tot.hum_pond / tot.peso_ley_humedad ELSE 0 END, 4),
        \'lotes_aprobados\', tot.lotes_aprobados,
        \'valorizacion_compra_usd\', tot.valor_compra_usd,
        \'valor_por_tonelada_usd\', ROUND(CASE WHEN tot.peso_valorizado > 0 THEN tot.ptn_ponderado / tot.peso_valorizado ELSE 0 END, 2),
        \'valorizacion_venta_usd\', tot.valor_venta_usd,
        \'toneladas_despachadas\', tot.toneladas_despachadas,
        \'despachos\', tot.despachos,
        \'lotes_validados\', tot.validados,
        \'lotes_analizados\', tot.analizados,
        \'lotes_con_cierre_leyes\', tot.con_cierre_leyes,
        \'lotes_con_valor_comercial\', tot.con_valor,
        \'lotes_valorizados\', tot.valorizados,
        \'lotes_despachados\', tot.despachados
    ) AS kpis,

    (SELECT JSON_ARRAYAGG(JSON_OBJECT(
        \'etapa\', e.etapa,
        \'conteo\', e.conteo,
        \'porcentaje\', e.porcentaje
    )) FROM (
        SELECT em.etapa, em.conteo,
            ROUND(em.conteo * 100.0 / NULLIF(FIRST_VALUE(em.conteo) OVER (ORDER BY em.orden), 0), 2) AS porcentaje
        FROM emb em
    ) e) AS embudo,

    (SELECT JSON_ARRAYAGG(JSON_OBJECT(
        \'razon_social\', t.razon_social,
        \'valor_usd\', t.valor_usd,
        \'toneladas\', t.toneladas
    )) FROM (
        SELECT p.razon_social,
            COALESCE(SUM(vcd.subtotal), 0) AS valor_usd,
            COALESCE(SUM(COALESCE(NULLIF(lg.peso_neto, 0), lm.peso_neto, 0)), 0) / 1000 AS toneladas
        FROM valorizacion_compra vc
        INNER JOIN valorizacion_compramineral_detalle vcd ON vcd.id_valorizacion_compra = vc.id
        INNER JOIN proveedor p ON p.id = vc.id_proveedor_minero
        LEFT JOIN lote_guia lg ON lg.id = vcd.id_lote_guia
        LEFT JOIN lote_mineral lm ON lm.id = lg.id_lote_mineral
        CROSS JOIN prm p2
        WHERE vc.fecha_hora_valorizacion BETWEEN p2.fi AND p2.ff
          AND vc.estado <> \'Anulado\'
        GROUP BY p.id, p.razon_social
        ORDER BY valor_usd DESC
        LIMIT 8
    ) t) AS por_proveedor,

    (SELECT JSON_ARRAYAGG(JSON_OBJECT(
        \'elemento\', t.elemento_quimico,
        \'valor_usd\', t.valor_usd
    )) FROM (
        SELECT vcd.elemento_quimico, COALESCE(SUM(vcd.subtotal), 0) AS valor_usd
        FROM valorizacion_compra vc
        INNER JOIN valorizacion_compramineral_detalle vcd ON vcd.id_valorizacion_compra = vc.id
        CROSS JOIN prm p
        WHERE vc.fecha_hora_valorizacion BETWEEN p.fi AND p.ff
          AND vc.estado <> \'Anulado\'
        GROUP BY vcd.elemento_quimico
    ) t) AS por_elemento,

    (SELECT JSON_ARRAYAGG(JSON_OBJECT(
        \'estado\', t.estado,
        \'lotes\', t.lotes,
        \'toneladas\', t.toneladas
    )) FROM (
        SELECT CASE
            WHEN lm.esta_valorizado_oro = 1 OR lm.esta_valorizado_plata = 1 THEN \'Valorizado\'
            WHEN COALESCE(lm.con_valor_comercial, 0) = 1 THEN \'Con valor comercial\'
            ELSE \'Sin valor comercial\'
        END AS estado,
        COUNT(*) AS lotes,
        COALESCE(SUM(lm.peso_neto), 0) / 1000 AS toneladas
        FROM lote_mineral lm
        INNER JOIN recepcion_unidad ru ON ru.id = lm.id_recepcion_unidad
        CROSS JOIN prm p
        WHERE COALESCE(lm.fecha_hora_peso_final, lm.created_at) BETWEEN p.fi AND p.ff
          AND (p.suc IS NULL OR ru.id_sucursal = p.suc)
        GROUP BY estado
    ) t) AS por_estado_lote,

    (SELECT JSON_ARRAYAGG(JSON_OBJECT(
        \'estado\', t.estado,
        \'conteo\', t.conteo,
        \'valor_usd\', t.valor_usd
    )) FROM (
        SELECT vc.estado,
            COUNT(DISTINCT vc.id) AS conteo,
            COALESCE(SUM(vcd.subtotal), 0) AS valor_usd
        FROM valorizacion_compra vc
        LEFT JOIN valorizacion_compramineral_detalle vcd ON vcd.id_valorizacion_compra = vc.id
        CROSS JOIN prm p
        WHERE vc.fecha_hora_valorizacion BETWEEN p.fi AND p.ff
        GROUP BY vc.estado
    ) t) AS estado_valorizaciones,

    (SELECT JSON_ARRAYAGG(JSON_OBJECT(
        \'correlativo\', t.correlativo,
        \'guia_remitente\', t.guia_remitente,
        \'peso_declarado_t\', t.peso_declarado_t,
        \'peso_real_t\', t.peso_real_t,
        \'merma_pct\', t.merma_pct
    )) FROM (
        SELECT lm.correlativo,
            g.guia_remitente,
            g.peso_guia_remision / 1000 AS peso_declarado_t,
            COALESCE(NULLIF(lg.peso_neto, 0), lm.peso_neto, 0) / 1000 AS peso_real_t,
            ROUND(((g.peso_guia_remision - COALESCE(NULLIF(lg.peso_neto, 0), lm.peso_neto, 0)) / NULLIF(g.peso_guia_remision, 0)) * 100, 3) AS merma_pct
        FROM lote_mineral lm
        INNER JOIN lote_guia lg ON lg.id_lote_mineral = lm.id
        INNER JOIN guia_primer_tramo g ON g.id = lg.id_guia_primer_tramo
        INNER JOIN recepcion_unidad ru ON ru.id = lm.id_recepcion_unidad
        CROSS JOIN prm p
        WHERE g.peso_guia_remision > 0
          AND COALESCE(lm.fecha_hora_peso_final, lm.created_at) BETWEEN p.fi AND p.ff
          AND (p.suc IS NULL OR ru.id_sucursal = p.suc)
        ORDER BY ABS(merma_pct) DESC
        LIMIT 15
    ) t) AS merma,

    (SELECT JSON_OBJECT(
        \'guias_total\', t.guias_total,
        \'guias_no_conformidad\', t.no_conformidad,
        \'porcentaje\', ROUND(t.no_conformidad * 100.0 / NULLIF(t.guias_total, 0), 2)
    ) FROM (
        SELECT COUNT(*) AS guias_total,
            SUM(CASE WHEN g.con_no_conformidad = 1 THEN 1 ELSE 0 END) AS no_conformidad
        FROM guia_primer_tramo g
        INNER JOIN recepcion_unidad ru ON ru.id = g.id_recepcion_unidad
        CROSS JOIN prm p
        WHERE COALESCE(g.fecha_emision, g.created_at) BETWEEN p.fi AND p.ff
          AND (p.suc IS NULL OR ru.id_sucursal = p.suc)
    ) t) AS no_conformidad,

    (SELECT JSON_OBJECT(
        \'ingreso_a_pesaje_h\', (
            SELECT ROUND(AVG(TIMESTAMPDIFF(MINUTE, ru.fecha_hora_ingreso, ru.fecha_hora_inicio_pesaje)) / 60, 2)
            FROM recepcion_unidad ru
            CROSS JOIN prm p
            WHERE ru.fecha_hora_ingreso BETWEEN p.fi AND p.ff
              AND ru.fecha_hora_inicio_pesaje IS NOT NULL
              AND (p.suc IS NULL OR ru.id_sucursal = p.suc)
        ),
        \'pesaje_a_analisis_h\', (
            SELECT ROUND(AVG(TIMESTAMPDIFF(MINUTE, lm.fecha_hora_peso_final, cf.confirmado_at)) / 60, 2)
            FROM lote_mineral lm
            INNER JOIN recepcion_unidad ru ON ru.id = lm.id_recepcion_unidad
            INNER JOIN (
                SELECT ga.id_lote_mineral, MAX(am.created_at) AS confirmado_at
                FROM analisis_mineral am
                INNER JOIN grupo_analisis ga ON ga.id = am.id_grupo_analisis
                WHERE (am.ley_oro_confirmada = 1 OR am.ley_plata_confirmada = 1)
                GROUP BY ga.id_lote_mineral
            ) cf ON cf.id_lote_mineral = lm.id
            CROSS JOIN prm p
            WHERE COALESCE(lm.fecha_hora_peso_final, lm.created_at) BETWEEN p.fi AND p.ff
              AND (p.suc IS NULL OR ru.id_sucursal = p.suc)
        )
    )) AS tiempos,

    (SELECT JSON_OBJECT(
        \'unidades_en_planta\', (SELECT COUNT(*) FROM recepcion_unidad WHERE estado = \'En Planta\'),
        \'visitas_abiertas\', (SELECT COUNT(*) FROM recepcion_visita WHERE fecha_hora_salida IS NULL),
        \'guias_activas\', (SELECT COUNT(*) FROM guia_primer_tramo WHERE estado = \'Activo\'),
        \'lotes_sin_validar\', (SELECT COUNT(*) FROM lote_mineral WHERE esta_validado = 0 AND (estado IS NULL OR estado <> \'Eliminado\')),
        \'valorizaciones_pendientes\', (SELECT COUNT(*) FROM valorizacion_compra WHERE estado = \'Propuesta\')
    )) AS operativo,

    (SELECT JSON_OBJECT(
        \'saldo_anticipos_pen\', (SELECT COALESCE(SUM(saldo_actual), 0) FROM anticipo_proveedor WHERE estado = \'Con Saldo\'),
        \'deuda_proveedores_pen\', (
            SELECT COALESCE(SUM(x.total - x.pagado), 0) FROM (
                SELECT cc.total,
                    (SELECT COALESCE(SUM(pg.monto_pagado), 0) FROM pago_comprobante_compra pg WHERE pg.id_comprobante_compra = cc.id AND pg.es_anulado = 0) AS pagado
                FROM comprobante_compra cc
                WHERE cc.estado <> \'Anulado\'
            ) x
        ),
        \'comprobantes_emitidos\', (SELECT COUNT(*) FROM comprobante_compra WHERE estado <> \'Anulado\')
    )) AS financiero,

    (SELECT JSON_OBJECT(
        \'fecha\', tc.fecha,
        \'valor_compra\', tc.valor_compra,
        \'valor_venta\', tc.valor_venta
    ) FROM tipo_cambio tc WHERE tc.estado = \'Activo\' ORDER BY tc.fecha DESC LIMIT 1) AS tipo_cambio

FROM tot
';
    }

    /**
     * Decodificar la fila cruda de la consulta al contrato del dashboard.
     *
     * @return array<string,mixed>
     */
    private static function decodificar(?object $row): array
    {
        if (! $row) {
            return self::vacio();
        }

        $decodificarJson = function ($valor, array $fallback) {
            if ($valor === null || $valor === '') {
                return $fallback;
            }
            $arr = json_decode((string) $valor, true);

            return is_array($arr) ? $arr : $fallback;
        };

        return [
            'kpis' => $decodificarJson($row->kpis ?? null, []),
            'serie' => $decodificarJson($row->serie ?? null, []),
            'embudo' => $decodificarJson($row->embudo ?? null, []),
            'por_proveedor' => $decodificarJson($row->por_proveedor ?? null, []),
            'por_elemento' => $decodificarJson($row->por_elemento ?? null, []),
            'por_estado_lote' => $decodificarJson($row->por_estado_lote ?? null, []),
            'estado_valorizaciones' => $decodificarJson($row->estado_valorizaciones ?? null, []),
            'merma' => $decodificarJson($row->merma ?? null, []),
            'no_conformidad' => $decodificarJson($row->no_conformidad ?? null, []),
            'tiempos' => $decodificarJson($row->tiempos ?? null, []),
            'operativo' => $decodificarJson($row->operativo ?? null, []),
            'financiero' => $decodificarJson($row->financiero ?? null, []),
            'tipo_cambio' => $decodificarJson($row->tipo_cambio ?? null, []),
        ];
    }

    /**
     * Estructura vacía para cuando la consulta no devuelve filas.
     *
     * @return array<string,mixed>
     */
    private static function vacio(): array
    {
        return [
            'kpis' => [],
            'serie' => [],
            'embudo' => [],
            'por_proveedor' => [],
            'por_elemento' => [],
            'por_estado_lote' => [],
            'estado_valorizaciones' => [],
            'merma' => [],
            'no_conformidad' => [],
            'tiempos' => [],
            'operativo' => [],
            'financiero' => [],
            'tipo_cambio' => [],
        ];
    }
}
