<?php

namespace App\Modules\RecepcionMineral\Data;

use App\Modules\RecepcionUnidades\Data\RecepcionUnidadesData;
use App\Shared\Enums\_Generic\EstadoBase;
use App\Shared\Enums\_Generic\EstadoPesaje;
use Illuminate\Support\Facades\DB;

class RecepcionMineralData
{
    /**
     * Obtener el listado de recepciones para el módulo de mineral, filtradas por sucursal
     */
    public static function get_recepciones_mineral(array $filters)
    {
        $sql = '
        SELECT
            ru.id,
            ru.id_empleado_recepcion AS id_empleado_registro,
            CONCAT(emp_reg.nombre, " ", emp_reg.apellido) AS empleado_registro_nombre,
            ru.id_vehiculo,
            v.placa AS vehiculo_placa,
            NULL AS vehiculo_serie,
            ru.id_empresa_transporte,
            et.razon_social AS empresa_transporte_razon_social,
            ru.id_tipo_vehiculo,
            tv.nombre AS tipo_vehiculo_nombre,
            ru.id_conductor,
            CONCAT(c.nombre, " ", c.apellido) AS conductor_nombre_completo,
            c.dni AS conductor_dni,
            ru.tipo_ingreso,
            ru.id_vehiculo_carreta,
            vc.placa AS vehiculo_carreta_placa,
            ru.fecha_hora_ingreso,
            ru.fecha_hora_salida,
            ru.fecha_hora_inicio_pesaje,
            ru.fecha_hora_final_pesaje,
            ru.evidencias,
            ru.observacion,
            ru.estado,
            ru.estado_salida,
            ru.estado_pesaje,
            ru.id_sucursal AS id_sucursal,
            ru.id_distribucion AS id_distribucion,
            ru.es_recepcion_ficticia,
            ru.es_programacion,
            ru.documentos_programacion,
            ru.id_proveedor_minero,
            prr.razon_social AS proveedor_nombre_recepcion_unidad
        FROM
            recepcion_unidad ru
        LEFT JOIN empleado emp_reg ON emp_reg.id = ru.id_empleado_recepcion
        LEFT JOIN vehiculo v ON v.id = ru.id_vehiculo
        LEFT JOIN vehiculo vc ON vc.id = ru.id_vehiculo_carreta
        LEFT JOIN empresa_transporte et ON et.id = ru.id_empresa_transporte
        LEFT JOIN tipo_vehiculo tv ON tv.id = ru.id_tipo_vehiculo
        LEFT JOIN conductor c ON c.id = ru.id_conductor
        LEFT JOIN proveedor prr ON prr.id = ru.id_proveedor_minero
        WHERE ru.id_sucursal = :id_sucursal
          AND ru.estado = "En Planta"
        ';

        $params = ['id_sucursal' => (int) $filters['id_sucursal']];

        if (! empty($filters['estado_pesaje'])) {
            $sql .= ' AND ru.estado_pesaje = :estado_pesaje';
            $params['estado_pesaje'] = $filters['estado_pesaje'];
        } else {
            $sql .= ' AND ru.estado_pesaje IN ("Sin Pesar", "En Proceso")';
        }

        $sql .= ' AND ru.es_recepcion_ficticia = 0';

        $sql .= ' ORDER BY ru.fecha_hora_ingreso DESC;';

        $results = DB::select($sql, $params);

        // IDs de recepciones tipo Despacho para cargar sus detalles de distribución.
        $despachoIds = [];
        foreach ($results as $r) {
            if (($r->tipo_ingreso ?? null) === 'Despacho de Mineral') {
                $despachoIds[] = (int) $r->id;
            }
        }
        $distribucionesPorRecepcion = self::get_distribucion_detalles_by_recepciones($despachoIds);

        foreach ($results as $item) {
            if (isset($item->evidencias)) {
                $item->evidencias = json_decode($item->evidencias, true) ?? [];
            }
            $item->documentos_programacion = RecepcionUnidadesData::normalizar_documentos_programacion(
                $item->documentos_programacion ?? null,
            );
            $item->id_proveedor_minero = $item->id_proveedor_minero !== null ? (int) $item->id_proveedor_minero : null;
            $item->id_distribucion = isset($item->id_distribucion) && $item->id_distribucion !== null
                ? (int) $item->id_distribucion
                : null;
            // Obtener los lotes de esta recepción
            $item->lotes = self::get_lotes_by_recepcion($item->id);
            // Adjuntar los detalles de distribución (vacío si no es despacho o no hay detalles)
            $item->distribucion_detalles = $distribucionesPorRecepcion[(int) $item->id] ?? [];
        }

        return $results;
    }

    /**
     * Obtener los detalles de distribución para varias recepciones (solo aplica a recepciones tipo Despacho).
     *
     * @param  array<int, int>  $idRecepcionesUnidad
     * @return array<int, array<int, object>> indexado por id de recepción_unidad
     */
    public static function get_distribucion_detalles_by_recepciones(array $idRecepcionesUnidad): array
    {
        $out = [];
        if (empty($idRecepcionesUnidad)) {
            return $out;
        }

        $placeholders = implode(',', array_fill(0, count($idRecepcionesUnidad), '?'));
        $sql = "
            SELECT
                ddt.id,
                ddt.id_distribucion,
                ddt.id_despacho_detalle,
                ddt.numero_particion,
                ddt.peso_tomado,
                ddt.id_ticket_balanza,
                ddt.peso_tara,
                ddt.fecha_hora_peso_tara,
                ddt.peso_bruto,
                ddt.fecha_hora_peso_bruto,
                ddt.peso_neto,
                ddt.peso_tara_confirmado,
                ddt.peso_bruto_confirmado,
                ru.id AS recepcion_unidad_id,
                dd.id_lote_mineral AS detalle_id_lote_mineral,
                dd.id_blending AS detalle_id_blending,
                lm.correlativo AS lote_correlativo,
                lm.ley_humedad AS lote_ley_humedad,
                b.correlativo AS blending_correlativo,
                b.ley_humedad AS blending_ley_humedad,
                pr.razon_social AS proveedor_razon_social,
                tb.correlativo AS ticket_correlativo,
                d.correlativo AS despacho_correlativo,
                d.es_anulado AS despacho_es_anulado
            FROM distribucion_detalle ddt
            INNER JOIN distribucion di ON di.id = ddt.id_distribucion
            INNER JOIN despacho_detalle dd ON dd.id = ddt.id_despacho_detalle
            INNER JOIN despacho d ON d.id = dd.id_despacho
            INNER JOIN recepcion_unidad ru ON ru.id_distribucion = di.id
            LEFT JOIN lote_mineral lm ON lm.id = dd.id_lote_mineral
            LEFT JOIN blending b ON b.id = dd.id_blending
            LEFT JOIN proveedor pr ON pr.id = lm.id_proveedor_minero
            LEFT JOIN ticket_balanza tb ON tb.id = ddt.id_ticket_balanza
            WHERE ru.id IN ($placeholders)
            ORDER BY ru.id, ddt.id ASC
        ";
        $rows = DB::select($sql, $idRecepcionesUnidad);

        foreach ($rows as $r) {
            $r->id = (int) $r->id;
            $r->id_distribucion = (int) $r->id_distribucion;
            $r->id_despacho_detalle = (int) $r->id_despacho_detalle;
            $r->numero_particion = $r->numero_particion !== null ? (int) $r->numero_particion : null;
            $r->peso_tomado = (float) $r->peso_tomado;
            $r->id_ticket_balanza = $r->id_ticket_balanza !== null ? (int) $r->id_ticket_balanza : null;
            $r->peso_tara = $r->peso_tara !== null ? (float) $r->peso_tara : null;
            $r->fecha_hora_peso_tara = $r->fecha_hora_peso_tara !== null ? (string) $r->fecha_hora_peso_tara : null;
            $r->peso_bruto = $r->peso_bruto !== null ? (float) $r->peso_bruto : null;
            $r->fecha_hora_peso_bruto = $r->fecha_hora_peso_bruto !== null ? (string) $r->fecha_hora_peso_bruto : null;
            $r->peso_neto = $r->peso_neto !== null ? (float) $r->peso_neto : null;
            $r->peso_tara_confirmado = (bool) $r->peso_tara_confirmado;
            $r->peso_bruto_confirmado = (bool) $r->peso_bruto_confirmado;
            $r->lote_ley_humedad = $r->lote_ley_humedad !== null ? (float) $r->lote_ley_humedad : null;
            $r->blending_ley_humedad = $r->blending_ley_humedad !== null ? (float) $r->blending_ley_humedad : null;
            $r->ticket_correlativo = $r->ticket_correlativo !== null ? (string) $r->ticket_correlativo : null;
            $r->despacho_correlativo = $r->despacho_correlativo !== null ? (string) $r->despacho_correlativo : null;
            $r->despacho_es_anulado = (bool) $r->despacho_es_anulado;
            $r->detalle_id_lote_mineral = $r->detalle_id_lote_mineral !== null ? (int) $r->detalle_id_lote_mineral : null;
            $r->detalle_id_blending = $r->detalle_id_blending !== null ? (int) $r->detalle_id_blending : null;
            $r->recepcion_unidad_id = (int) $r->recepcion_unidad_id;

            $out[$r->recepcion_unidad_id][] = $r;
        }

        return $out;
    }

    /**
     * Obtener los lotes de mineral asociados a una recepción de unidad
     */
    public static function get_lotes_by_recepcion(int $recepcionUnidadId): array
    {
        $sql = '
        SELECT
            lm.id,
            lm.id_recepcion_unidad,
            lm.id_empresa,
            emp_tit.razon_social AS empresa_nombre,
            lm.id_proveedor_minero,
            p.razon_social AS proveedor_nombre,
            p.telefono AS proveedor_telefono,
            ru.id_proveedor_minero AS id_proveedor_minero_recepcion,
            pr.razon_social AS proveedor_nombre_recepcion,
            lm.id_empleado_registro,
            CONCAT(emp.nombre, " ", emp.apellido) AS empleado_registro_nombre,
            lm.id_zona_origen,
            zo.nombre AS zona_origen_nombre,
            lm.correlativo,
            lm.numero_correlativo,
            lm.numero_contacto,
            lm.tipo_producto,
            lm.tipo_mineral,
            lm.condicion_ingreso,
            lm.log_cambios,
            lm.evidencias,
            lm.peso_inicial,
            lm.fecha_hora_peso_inicial,
                        lm.peso_final,
            lm.fecha_hora_peso_final,
                        lm.peso_neto,
            lm.peso_actual,
            lm.tiene_particion,
            lm.particionado_desde_balanza,
            lm.particion_finalizada,
            lm.id_empleado_fin_particion,
            lm.fecha_hora_fin_particion,
            lm.estado,
            ru.id_vehiculo,
            v_lote.placa AS vehiculo_placa,
            NULL AS vehiculo_serie,
            ru.id_empresa_transporte,
            et_lote.razon_social AS empresa_transporte_razon_social,
            ru.id_tipo_vehiculo,
            tv_lote.nombre AS tipo_vehiculo_nombre,
            ru.id_conductor,
            CONCAT(c_lote.nombre, " ", c_lote.apellido) AS conductor_nombre_completo,
            c_lote.dni AS conductor_dni,
            lm.created_at
        FROM
            lote_mineral lm
        INNER JOIN recepcion_unidad ru ON ru.id = lm.id_recepcion_unidad
        INNER JOIN empleado emp ON emp.id = lm.id_empleado_registro
        LEFT JOIN empresa emp_tit ON emp_tit.id = lm.id_empresa
        LEFT JOIN proveedor p ON p.id = lm.id_proveedor_minero
        LEFT JOIN proveedor pr ON pr.id = ru.id_proveedor_minero
        LEFT JOIN zona_origen zo ON zo.id = lm.id_zona_origen
        LEFT JOIN vehiculo v_lote ON v_lote.id = ru.id_vehiculo
        LEFT JOIN empresa_transporte et_lote ON et_lote.id = ru.id_empresa_transporte
        LEFT JOIN tipo_vehiculo tv_lote ON tv_lote.id = ru.id_tipo_vehiculo
        LEFT JOIN conductor c_lote ON c_lote.id = ru.id_conductor
        WHERE
            lm.id_recepcion_unidad = :recepcion_unidad_id
            AND (lm.estado IS NULL OR lm.estado != "Eliminado")
        ORDER BY lm.correlativo ASC
        ';

        $results = DB::select($sql, ['recepcion_unidad_id' => $recepcionUnidadId]);

        foreach ($results as $item) {
            if (isset($item->evidencias)) {
                $item->evidencias = json_decode($item->evidencias, true) ?? [];
            }
            $item->id_empresa = $item->id_empresa !== null ? (int) $item->id_empresa : null;
            $item->peso_inicial = $item->peso_inicial !== null ? (float) $item->peso_inicial : null;
            $item->peso_final = $item->peso_final !== null ? (float) $item->peso_final : null;
            $item->peso_neto = $item->peso_neto !== null ? (float) $item->peso_neto : null;
            $item->peso_actual = $item->peso_actual !== null ? (float) $item->peso_actual : null;
            $item->tiene_particion = $item->tiene_particion !== null ? (bool) $item->tiene_particion : false;
            $item->particionado_desde_balanza = $item->particionado_desde_balanza !== null ? (bool) $item->particionado_desde_balanza : false;
            $item->particion_finalizada = $item->particion_finalizada !== null ? (bool) $item->particion_finalizada : false;
            $item->id_empleado_fin_particion = $item->id_empleado_fin_particion !== null ? (int) $item->id_empleado_fin_particion : null;
            $item->fecha_hora_fin_particion = $item->fecha_hora_fin_particion !== null ? (string) $item->fecha_hora_fin_particion : null;
            $item->id_vehiculo = $item->id_vehiculo !== null ? (int) $item->id_vehiculo : null;
            $item->id_empresa_transporte = $item->id_empresa_transporte !== null ? (int) $item->id_empresa_transporte : null;
            $item->id_tipo_vehiculo = $item->id_tipo_vehiculo !== null ? (int) $item->id_tipo_vehiculo : null;
            $item->id_conductor = $item->id_conductor !== null ? (int) $item->id_conductor : null;
            $item->log_cambios = isset($item->log_cambios) ? json_decode($item->log_cambios, true) ?? [] : [];
        }

        return $results;
    }

    /**
     * Obtener un lote específico por su ID
     */
    public static function get_lote_by_id(int $id)
    {
        $sql = '
        SELECT
            lm.id,
            lm.id_recepcion_unidad,
            lm.id_empresa,
            emp_tit.razon_social AS empresa_nombre,
            lm.id_proveedor_minero,
            p.razon_social AS proveedor_nombre,
            p.telefono AS proveedor_telefono,
            ru.id_proveedor_minero AS id_proveedor_minero_recepcion,
            pr.razon_social AS proveedor_nombre_recepcion,
            lm.id_empleado_registro,
            CONCAT(emp.nombre, " ", emp.apellido) AS empleado_registro_nombre,
            lm.id_zona_origen,
            zo.nombre AS zona_origen_nombre,
            lm.correlativo,
            lm.numero_correlativo,
            lm.numero_contacto,
            lm.tipo_producto,
            lm.tipo_mineral,
            lm.condicion_ingreso,
            lm.log_cambios,
            lm.evidencias,
            lm.peso_inicial,
            lm.fecha_hora_peso_inicial,
                        lm.peso_final,
            lm.fecha_hora_peso_final,
                        lm.peso_neto,
            lm.peso_actual,
            lm.tiene_particion,
            lm.particionado_desde_balanza,
            lm.particion_finalizada,
            lm.id_empleado_fin_particion,
            lm.fecha_hora_fin_particion,
            lm.estado,
            ru.id_vehiculo,
            v_lote.placa AS vehiculo_placa,
            NULL AS vehiculo_serie,
            ru.id_empresa_transporte,
            et_lote.razon_social AS empresa_transporte_razon_social,
            ru.id_tipo_vehiculo,
            tv_lote.nombre AS tipo_vehiculo_nombre,
            ru.id_conductor,
            CONCAT(c_lote.nombre, " ", c_lote.apellido) AS conductor_nombre_completo,
            c_lote.dni AS conductor_dni,
            c_lote.numero_licencia AS conductor_licencia,
            lm.created_at
        FROM
            lote_mineral lm
        LEFT JOIN recepcion_unidad ru ON ru.id = lm.id_recepcion_unidad
        INNER JOIN empleado emp ON emp.id = lm.id_empleado_registro
        LEFT JOIN empresa emp_tit ON emp_tit.id = lm.id_empresa
        LEFT JOIN proveedor p ON p.id = lm.id_proveedor_minero
        LEFT JOIN proveedor pr ON pr.id = ru.id_proveedor_minero
        LEFT JOIN zona_origen zo ON zo.id = lm.id_zona_origen
        LEFT JOIN vehiculo v_lote ON v_lote.id = ru.id_vehiculo
        LEFT JOIN empresa_transporte et_lote ON et_lote.id = ru.id_empresa_transporte
        LEFT JOIN tipo_vehiculo tv_lote ON tv_lote.id = ru.id_tipo_vehiculo
        LEFT JOIN conductor c_lote ON c_lote.id = ru.id_conductor
        WHERE
            lm.id = :id
        LIMIT 1
        ';

        $item = DB::selectOne($sql, ['id' => $id]);

        if ($item) {
            if (isset($item->evidencias)) {
                $item->evidencias = json_decode($item->evidencias, true) ?? [];
            }
            $item->id_empresa = $item->id_empresa !== null ? (int) $item->id_empresa : null;
            $item->peso_inicial = $item->peso_inicial !== null ? (float) $item->peso_inicial : null;
            $item->peso_final = $item->peso_final !== null ? (float) $item->peso_final : null;
            $item->peso_neto = $item->peso_neto !== null ? (float) $item->peso_neto : null;
            $item->peso_actual = $item->peso_actual !== null ? (float) $item->peso_actual : null;
            $item->tiene_particion = $item->tiene_particion !== null ? (bool) $item->tiene_particion : false;
            $item->particionado_desde_balanza = $item->particionado_desde_balanza !== null ? (bool) $item->particionado_desde_balanza : false;
            $item->particion_finalizada = $item->particion_finalizada !== null ? (bool) $item->particion_finalizada : false;
            $item->id_empleado_fin_particion = $item->id_empleado_fin_particion !== null ? (int) $item->id_empleado_fin_particion : null;
            $item->fecha_hora_fin_particion = $item->fecha_hora_fin_particion !== null ? (string) $item->fecha_hora_fin_particion : null;
            $item->id_vehiculo = $item->id_vehiculo !== null ? (int) $item->id_vehiculo : null;
            $item->id_empresa_transporte = $item->id_empresa_transporte !== null ? (int) $item->id_empresa_transporte : null;
            $item->id_tipo_vehiculo = $item->id_tipo_vehiculo !== null ? (int) $item->id_tipo_vehiculo : null;
            $item->id_conductor = $item->id_conductor !== null ? (int) $item->id_conductor : null;
            $item->log_cambios = isset($item->log_cambios) ? json_decode($item->log_cambios, true) ?? [] : [];

            return (array) $item;
        }

        return null;
    }

    /**
     * Obtener una recepción de unidad específica por su ID con sus lotes
     */
    public static function get_recepcion_by_id_with_lotes(int $id)
    {
        $sql = '
        SELECT
            ru.id,
            ru.id_empleado_recepcion AS id_empleado_registro,
            CONCAT(emp_reg.nombre, " ", emp_reg.apellido) AS empleado_registro_nombre,
            ru.id_vehiculo,
            v.placa AS vehiculo_placa,
            NULL AS vehiculo_serie,
            ru.id_empresa_transporte,
            et.razon_social AS empresa_transporte_razon_social,
            ru.id_tipo_vehiculo,
            tv.nombre AS tipo_vehiculo_nombre,
            ru.id_conductor,
            CONCAT(c.nombre, " ", c.apellido) AS conductor_nombre_completo,
            c.dni AS conductor_dni,
            ru.tipo_ingreso,
            ru.id_vehiculo_carreta,
            vc.placa AS vehiculo_carreta_placa,
            ru.fecha_hora_ingreso,
            ru.fecha_hora_salida,
            ru.fecha_hora_inicio_pesaje,
            ru.fecha_hora_final_pesaje,
            ru.evidencias,
            ru.observacion,
            ru.estado,
            ru.estado_salida,
            ru.estado_pesaje,
            ru.id_sucursal AS id_sucursal,
            ru.id_distribucion AS id_distribucion,
            ru.es_recepcion_ficticia,
            ru.es_programacion,
            ru.documentos_programacion
        FROM
            recepcion_unidad ru
        LEFT JOIN empleado emp_reg ON emp_reg.id = ru.id_empleado_recepcion
        LEFT JOIN vehiculo v ON v.id = ru.id_vehiculo
        LEFT JOIN vehiculo vc ON vc.id = ru.id_vehiculo_carreta
        LEFT JOIN empresa_transporte et ON et.id = ru.id_empresa_transporte
        LEFT JOIN tipo_vehiculo tv ON tv.id = ru.id_tipo_vehiculo
        LEFT JOIN conductor c ON c.id = ru.id_conductor
        WHERE ru.id = :id
        LIMIT 1
        ';

        $item = DB::selectOne($sql, ['id' => $id]);

        if ($item) {
            if (isset($item->evidencias)) {
                $item->evidencias = json_decode($item->evidencias, true) ?? [];
            }
            $item->documentos_programacion = RecepcionUnidadesData::normalizar_documentos_programacion(
                $item->documentos_programacion ?? null,
            );
            $item->id_distribucion = isset($item->id_distribucion) && $item->id_distribucion !== null
                ? (int) $item->id_distribucion
                : null;
            $item->lotes = self::get_lotes_by_recepcion($id);
            if (($item->tipo_ingreso ?? null) === 'Despacho de Mineral') {
                $detalles = self::get_distribucion_detalles_by_recepciones([$id]);
                $item->distribucion_detalles = $detalles[$id] ?? [];
            } else {
                $item->distribucion_detalles = [];
            }

            return (array) $item;
        }

        return null;
    }

    /**
     * Obtener el resumen de balanza con UNION ALL de:
     *   - Bloque A: lote_mineral pesados en recepciones (Recepción de Mineral).
     *   - Bloque B: distribucion_detalle pesadas en despachos (Despacho de Mineral).
     *
     * El campo `tipo_pesaje` (`LOTE_RECEPCION` | `DISTRIBUCION_DETALLE`) discrimina la fila.
     * El campo `origen_tipo` (`LOTE` | `BLENDING`) sólo aplica a Bloque B e indica de qué se
     * generó el despacho_detalle (id_lote_mineral vs id_blending).
     */
    public static function get_resumen_balanza(array $filters): array
    {
        $estadoPesado = EstadoPesaje::Pesado->value;
        $idSucursal = (int) $filters['id_sucursal'];

        // ────────────────────────────────────────────────────────────────────
        // Bloque A: LOTE_RECEPCION (lote_mineral pesados al recibir mineral)
        // ────────────────────────────────────────────────────────────────────
        $sqlA = "
        SELECT
            'LOTE_RECEPCION'                            AS tipo_pesaje,
            'LOTE'                                     AS origen_tipo,
            NULL                                       AS id_distribucion_detalle,
            NULL                                       AS id_distribucion,
            NULL                                       AS id_despacho,
            NULL                                       AS id_despacho_detalle,
            NULL                                       AS despacho_correlativo,
            NULL                                       AS numero_particion,
            NULL                                       AS origen_correlativo,
            NULL                                       AS id_lote_origen,
            NULL                                       AS id_blending_origen,
            CONCAT('L-', lm.id)                         AS id_row,

            lm.id                                      AS id_lote,
            lm.correlativo                             AS lote_correlativo,
            lm.numero_correlativo                      AS lote_numero_correlativo,
            lm.numero_contacto                         AS lote_numero_contacto,
            lm.tipo_producto                           AS lote_tipo_producto,
            lm.tipo_mineral                            AS lote_tipo_mineral,
            lm.condicion_ingreso                       AS lote_condicion_ingreso,
            lm.evidencias                              AS lote_evidencias,
            lm.log_cambios                             AS lote_log_cambios,
            lm.created_at                              AS lote_fecha_creacion,
            lm.peso_inicial,
            lm.fecha_hora_peso_inicial,
                        lm.peso_final,
            lm.fecha_hora_peso_final,
                        -- Para lotes padre finalizados, el peso consolidado vive en
                        -- `peso_neto_oficial`; `peso_neto` queda en null porque la
                        -- finalización nunca lo asigna. COALESCE mantiene el alias
                        -- genérico que consume el frontend.
                        COALESCE(lm.peso_neto_oficial, lm.peso_neto) AS peso_neto,
            -- Aliases canónicos: para RECEPCIÓN el camión llega cargado (BRUTO = inicial)
            -- y retorna vacío (TARA = final).
            lm.peso_inicial                            AS peso_bruto,
            lm.fecha_hora_peso_inicial                 AS fecha_hora_peso_bruto,
            lm.peso_final                              AS peso_tara,
            lm.fecha_hora_peso_final                   AS fecha_hora_peso_tara,
            lm.id_ticket_balanza,
            COALESCE(lm.fecha_hora_fin_particion, lm.created_at) AS fecha_pesaje,

            ru.tipo_ingreso,
            ru.id                                      AS id_recepcion_unidad,
            ru.fecha_hora_ingreso,
            ru.fecha_hora_salida,
            ru.id_vehiculo_carreta,
            vc.placa                                   AS vehiculo_carreta_placa,
            ru.estado_pesaje,

            ru.id_vehiculo,
            v.placa                                    AS vehiculo_placa,

            ru.id_empresa_transporte,
            et.razon_social                            AS empresa_transporte_razon_social,

            ru.id_tipo_vehiculo,
            tv.nombre                                  AS tipo_vehiculo_nombre,

            p.id                                       AS id_proveedor,
            p.razon_social                             AS proveedor_razon_social,

            ru.id_proveedor_minero                     AS id_proveedor_minero_recepcion,
            pr.razon_social                            AS proveedor_nombre_recepcion,

            zo.id                                      AS id_zona_origen,
            zo.nombre                                  AS zona_origen_nombre,

            ru.id_conductor,
            CONCAT(c.nombre, ' ', c.apellido)          AS conductor_nombre_completo,
            c.dni                                      AS conductor_dni,
            c.numero_licencia                          AS conductor_licencia,

            CONCAT(emp_reg.nombre, ' ', emp_reg.apellido) AS empleado_registro_nombre,

            NULL                                       AS observacion_peso_inicial,
            NULL                                       AS observacion_peso_final
        FROM lote_mineral lm
        -- LEFT JOIN porque el lote padre particionado tiene id_recepcion_unidad = NULL.
        LEFT JOIN recepcion_unidad ru ON ru.id = lm.id_recepcion_unidad
        LEFT JOIN vehiculo v          ON v.id = ru.id_vehiculo
        LEFT JOIN vehiculo vc         ON vc.id = ru.id_vehiculo_carreta
        LEFT JOIN empresa_transporte et ON et.id = ru.id_empresa_transporte
        LEFT JOIN tipo_vehiculo tv    ON tv.id = ru.id_tipo_vehiculo
        LEFT JOIN proveedor p        ON p.id = lm.id_proveedor_minero
        LEFT JOIN proveedor pr       ON pr.id = ru.id_proveedor_minero
        LEFT JOIN zona_origen zo     ON zo.id = lm.id_zona_origen
        LEFT JOIN conductor c        ON c.id = ru.id_conductor
        LEFT JOIN empleado emp_reg   ON emp_reg.id = ru.id_empleado_recepcion
        WHERE (
              -- (1) Lote regular pesado asignado a una unidad de la sucursal.
              (lm.id_recepcion_unidad IS NOT NULL
                 AND ru.id_sucursal = :id_sucursal_a
                 AND ru.estado_pesaje = :estado_pesaje_a
                 AND ru.tipo_ingreso = 'Recepción de Mineral')
              OR
              -- (2) Lote padre particionado y finalizado. No tiene unidad asignada,
              -- pero sus particiones hijas sí están en unidades de la sucursal.
              (lm.particionado_desde_balanza = 1
                 AND lm.particion_finalizada = 1
                 AND lm.id_recepcion_unidad IS NULL
                 AND lm.peso_neto_oficial IS NOT NULL
                 AND EXISTS (
                     SELECT 1
                     FROM particion_lote_mineral p
                     INNER JOIN recepcion_unidad ru2 ON ru2.id = p.id_recepcion_unidad
                     WHERE p.id_lote_mineral = lm.id
                       AND ru2.id_sucursal = :id_sucursal_a_padre
                       AND ru2.tipo_ingreso = 'Recepción de Mineral'
                 ))
          )
          AND (lm.estado IS NULL OR lm.estado != 'Eliminado')
        ";

        $paramsA = [
            'id_sucursal_a' => $idSucursal,
            'id_sucursal_a_padre' => $idSucursal,
            'estado_pesaje_a' => $estadoPesado,
        ];

        if (! empty($filters['fecha_inicio'])) {
            $sqlA .= ' AND DATE(lm.created_at) >= :fecha_inicio_a';
            $paramsA['fecha_inicio_a'] = $filters['fecha_inicio'];
        }
        if (! empty($filters['fecha_fin'])) {
            $sqlA .= ' AND DATE(lm.created_at) <= :fecha_fin_a';
            $paramsA['fecha_fin_a'] = $filters['fecha_fin'];
        }
        if (! empty($filters['tipo_ingreso']) && $filters['tipo_ingreso'] === 'Despacho de Mineral') {
            $sqlA .= ' AND 1=0'; // filtro excluye este bloque
        }
        if (! empty($filters['placa'])) {
            // El filtro de placa no aplica a lotes padre particionados (no tienen
            // vehículo directo). Sólo filtra lotes regulares.
            $sqlA .= ' AND (lm.id_recepcion_unidad IS NULL OR v.placa = :placa_a)';
            $paramsA['placa_a'] = $filters['placa'];
        }
        if (! empty($filters['lote_correlativo'])) {
            $sqlA .= ' AND lm.correlativo LIKE :lote_correlativo_a';
            $paramsA['lote_correlativo_a'] = '%'.$filters['lote_correlativo'].'%';
        }
        if (! empty($filters['id_empresa_transporte'])) {
            // Mismo razonamiento que el filtro de placa: sólo aplica a lotes regulares.
            $sqlA .= ' AND (lm.id_recepcion_unidad IS NULL OR ru.id_empresa_transporte = :id_empresa_transporte_a)';
            $paramsA['id_empresa_transporte_a'] = (int) $filters['id_empresa_transporte'];
        }

        // ────────────────────────────────────────────────────────────────────
        // Bloque B: DISTRIBUCION_DETALLE (despachos ya pesados)
        //   origen_tipo = 'LOTE' cuando dd.id_lote_mineral IS NOT NULL
        //   origen_tipo = 'BLENDING' cuando dd.id_blending IS NOT NULL
        // ────────────────────────────────────────────────────────────────────
        $sqlB = "
        SELECT
            'DISTRIBUCION_DETALLE'                     AS tipo_pesaje,
            CASE WHEN dd.id_lote_mineral IS NOT NULL THEN 'LOTE' ELSE 'BLENDING' END AS origen_tipo,
            ddt.id                                     AS id_distribucion_detalle,
            d.id                                       AS id_distribucion,
            ds.id                                      AS id_despacho,
            dd.id                                      AS id_despacho_detalle,
            ds.correlativo                             AS despacho_correlativo,
            ddt.numero_particion,
            COALESCE(lm_origen.correlativo, b_origen.correlativo) AS origen_correlativo,
            dd.id_lote_mineral                         AS id_lote_origen,
            dd.id_blending                             AS id_blending_origen,
            CONCAT('D-', ddt.id)                       AS id_row,

            NULL                                       AS id_lote,
            NULL                                       AS lote_correlativo,
            NULL                                       AS lote_numero_correlativo,
            NULL                                       AS lote_numero_contacto,
            NULL                                       AS lote_tipo_producto,
            NULL                                       AS lote_tipo_mineral,
            NULL                                       AS lote_condicion_ingreso,
            NULL                                       AS lote_evidencias,
            NULL                                       AS lote_log_cambios,
            d.created_at                               AS lote_fecha_creacion,
            -- Aliases LOTE-style: el modal de edición los consume (peso_inicial/final).
            ddt.peso_tara                              AS peso_inicial,
            ddt.fecha_hora_peso_tara                   AS fecha_hora_peso_inicial,
            ddt.peso_bruto                             AS peso_final,
            ddt.fecha_hora_peso_bruto                  AS fecha_hora_peso_final,
            ddt.peso_neto,
            -- Aliases canónicos (orden debe coincidir con Bloque A: peso_bruto antes de peso_tara)
            ddt.peso_bruto                             AS peso_bruto,
            ddt.fecha_hora_peso_bruto                  AS fecha_hora_peso_bruto,
            ddt.peso_tara                              AS peso_tara,
            ddt.fecha_hora_peso_tara                   AS fecha_hora_peso_tara,
            ddt.id_ticket_balanza,
            COALESCE(ddt.fecha_hora_peso_bruto, ddt.fecha_hora_peso_tara, d.created_at) AS fecha_pesaje,

            ru.tipo_ingreso,
            ru.id                                      AS id_recepcion_unidad,
            ru.fecha_hora_ingreso,
            ru.fecha_hora_salida,
            ru.id_vehiculo_carreta,
            vc.placa                                   AS vehiculo_carreta_placa,
            ru.estado_pesaje,

            ru.id_vehiculo,
            v.placa                                    AS vehiculo_placa,

            ru.id_empresa_transporte,
            et.razon_social                            AS empresa_transporte_razon_social,

            ru.id_tipo_vehiculo,
            tv.nombre                                  AS tipo_vehiculo_nombre,

            p_origen.id                                AS id_proveedor,
            p_origen.razon_social                      AS proveedor_razon_social,

            ru.id_proveedor_minero                     AS id_proveedor_minero_recepcion,
            pr.razon_social                            AS proveedor_nombre_recepcion,

            zo_origen.id                               AS id_zona_origen,
            zo_origen.nombre                           AS zona_origen_nombre,

            ru.id_conductor,
            CONCAT(c.nombre, ' ', c.apellido)          AS conductor_nombre_completo,
            c.dni                                      AS conductor_dni,
            c.numero_licencia                          AS conductor_licencia,

            CONCAT(emp_reg.nombre, ' ', emp_reg.apellido) AS empleado_registro_nombre,

            NULL                                       AS observacion_peso_inicial,
            NULL                                       AS observacion_peso_final
        FROM distribucion_detalle ddt
        INNER JOIN distribucion d            ON d.id  = ddt.id_distribucion
        INNER JOIN despacho ds               ON ds.id = d.id_despacho
        INNER JOIN despacho_detalle dd       ON dd.id = ddt.id_despacho_detalle
        INNER JOIN recepcion_unidad ru       ON ru.id_distribucion = d.id
        LEFT JOIN vehiculo v                 ON v.id = ru.id_vehiculo
        LEFT JOIN vehiculo vc                ON vc.id = ru.id_vehiculo_carreta
        LEFT JOIN empresa_transporte et      ON et.id = ru.id_empresa_transporte
        LEFT JOIN tipo_vehiculo tv           ON tv.id = ru.id_tipo_vehiculo
        LEFT JOIN lote_mineral lm_origen     ON lm_origen.id = dd.id_lote_mineral
        LEFT JOIN blending b_origen          ON b_origen.id = dd.id_blending
        LEFT JOIN proveedor p_origen         ON p_origen.id = lm_origen.id_proveedor_minero
        LEFT JOIN proveedor pr               ON pr.id = ru.id_proveedor_minero
        LEFT JOIN zona_origen zo_origen      ON zo_origen.id = lm_origen.id_zona_origen
        LEFT JOIN conductor c                ON c.id = ru.id_conductor
        LEFT JOIN empleado emp_reg           ON emp_reg.id = ru.id_empleado_recepcion
        WHERE ru.id_sucursal = :id_sucursal_b
          AND ru.estado_pesaje = :estado_pesaje_b
          AND ru.tipo_ingreso = 'Despacho de Mineral'
          AND ddt.peso_neto IS NOT NULL
          AND ds.es_anulado = 0
        ";

        $paramsB = [
            'id_sucursal_b' => $idSucursal,
            'estado_pesaje_b' => $estadoPesado,
        ];

        if (! empty($filters['fecha_inicio'])) {
            $sqlB .= ' AND DATE(COALESCE(ddt.fecha_hora_peso_bruto, ddt.fecha_hora_peso_tara, d.created_at)) >= :fecha_inicio_b';
            $paramsB['fecha_inicio_b'] = $filters['fecha_inicio'];
        }
        if (! empty($filters['fecha_fin'])) {
            $sqlB .= ' AND DATE(COALESCE(ddt.fecha_hora_peso_bruto, ddt.fecha_hora_peso_tara, d.created_at)) <= :fecha_fin_b';
            $paramsB['fecha_fin_b'] = $filters['fecha_fin'];
        }
        if (! empty($filters['tipo_ingreso']) && $filters['tipo_ingreso'] === 'Recepción de Mineral') {
            $sqlB .= ' AND 1=0'; // filtro excluye este bloque
        }
        if (! empty($filters['placa'])) {
            $sqlB .= ' AND v.placa = :placa_b';
            $paramsB['placa_b'] = $filters['placa'];
        }
        if (! empty($filters['lote_correlativo'])) {
            // Aplica a la columna de origen del despacho_detalle (sea lote o blending).
            $sqlB .= ' AND (lm_origen.correlativo LIKE :lote_correlativo_b1 OR b_origen.correlativo LIKE :lote_correlativo_b2)';
            $paramsB['lote_correlativo_b1'] = '%'.$filters['lote_correlativo'].'%';
            $paramsB['lote_correlativo_b2'] = '%'.$filters['lote_correlativo'].'%';
        }
        if (! empty($filters['id_empresa_transporte'])) {
            $sqlB .= ' AND ru.id_empresa_transporte = :id_empresa_transporte_b';
            $paramsB['id_empresa_transporte_b'] = (int) $filters['id_empresa_transporte'];
        }

        // Unificar params y SQL
        $sql = $sqlA."\n UNION ALL \n".$sqlB."\n ORDER BY fecha_pesaje DESC";
        $params = array_merge($paramsA, $paramsB);

        $results = DB::select($sql, $params);

        foreach ($results as $item) {
            // JSON decode condicional
            if (isset($item->lote_evidencias) && is_string($item->lote_evidencias)) {
                $item->lote_evidencias = json_decode($item->lote_evidencias, true) ?? [];
            } else {
                $item->lote_evidencias = $item->lote_evidencias ?? null;
            }
            if (isset($item->lote_log_cambios) && is_string($item->lote_log_cambios)) {
                $item->lote_log_cambios = json_decode($item->lote_log_cambios, true) ?? [];
            } else {
                $item->lote_log_cambios = $item->lote_log_cambios ?? null;
            }

            // Casteos numéricos
            $item->peso_inicial = $item->peso_inicial !== null ? (float) $item->peso_inicial : null;
            $item->peso_final = $item->peso_final !== null ? (float) $item->peso_final : null;
            $item->peso_neto = $item->peso_neto !== null ? (float) $item->peso_neto : null;
            $item->peso_tara = $item->peso_tara !== null ? (float) $item->peso_tara : null;
            $item->peso_bruto = $item->peso_bruto !== null ? (float) $item->peso_bruto : null;
            $item->id_recepcion_unidad = (int) $item->id_recepcion_unidad;
            $item->id_ticket_balanza = $item->id_ticket_balanza !== null ? (int) $item->id_ticket_balanza : null;
            $item->id_despacho = $item->id_despacho !== null ? (int) $item->id_despacho : null;
            $item->id_despacho_detalle = $item->id_despacho_detalle !== null ? (int) $item->id_despacho_detalle : null;
            $item->id_distribucion = $item->id_distribucion !== null ? (int) $item->id_distribucion : null;
            $item->id_distribucion_detalle = $item->id_distribucion_detalle !== null ? (int) $item->id_distribucion_detalle : null;
            $item->id_lote_origen = $item->id_lote_origen !== null ? (int) $item->id_lote_origen : null;
            $item->id_blending_origen = $item->id_blending_origen !== null ? (int) $item->id_blending_origen : null;
            $item->numero_particion = $item->numero_particion !== null ? (int) $item->numero_particion : null;
        }

        return $results;
    }

    /**
     * Obtener metadatos únicos para los filtros de la sucursal
     */
    public static function get_resumen_filtros(int $idSucursal): array
    {
        // 1. Obtener lotes de la sucursal
        $lotesSql = '
        SELECT DISTINCT lm.id, lm.correlativo
        FROM lote_mineral lm
        LEFT JOIN recepcion_unidad ru ON ru.id = lm.id_recepcion_unidad
        WHERE (
            -- Lote regular: tiene unidad y pertenece a la sucursal.
            (lm.id_recepcion_unidad IS NOT NULL
             AND ru.id_sucursal = :id_sucursal)
            OR
            -- Lote padre particionado y finalizado: no tiene unidad directa,
            -- pero sus particiones hijas sí están en unidades de la sucursal.
            (lm.particionado_desde_balanza = 1
             AND lm.particion_finalizada = 1
             AND lm.id_recepcion_unidad IS NULL
             AND EXISTS (
                 SELECT 1
                 FROM particion_lote_mineral p
                 INNER JOIN recepcion_unidad ru2 ON ru2.id = p.id_recepcion_unidad
                 WHERE p.id_lote_mineral = lm.id AND ru2.id_sucursal = :id_sucursal_2
             ))
        )
          AND (lm.estado IS NULL OR lm.estado != "Eliminado")
        ORDER BY lm.correlativo DESC;
        ';
        $lotes = DB::select($lotesSql, [
            'id_sucursal' => $idSucursal,
            'id_sucursal_2' => $idSucursal,
        ]);

        // 2. Obtener vehículos de la sucursal (de la recepción de unidad)
        $vehiculosSql = '
        SELECT DISTINCT v.id, v.placa
        FROM vehiculo v
        INNER JOIN recepcion_unidad ru ON ru.id_vehiculo = v.id
        WHERE ru.id_sucursal = :id_sucursal
          AND ru.tipo_ingreso = "Recepción de Mineral"
        ORDER BY v.placa ASC;
        ';
        $vehiculos = DB::select($vehiculosSql, ['id_sucursal' => $idSucursal]);

        // 3. Obtener condiciones de ingreso de la sucursal
        $condicionesSql = '
        SELECT DISTINCT ru.tipo_ingreso
        FROM recepcion_unidad ru
        WHERE ru.id_sucursal = :id_sucursal
          AND ru.tipo_ingreso IS NOT NULL
          AND ru.tipo_ingreso != ""
        ORDER BY ru.tipo_ingreso ASC;
        ';
        $condiciones = DB::select($condicionesSql, ['id_sucursal' => $idSucursal]);

        return [
            'lotes' => $lotes,
            'vehiculos' => $vehiculos,
            'condiciones_ingreso' => array_column($condiciones, 'tipo_ingreso'),
        ];
    }

    /**
     * Obtener la información completa para el Ticket de Balanza en formato PDF
     */
    public static function get_ticket_balanza_info(int $loteId)
    {
        $sql = "
        SELECT
            lot.id AS id_lote,
            lot.correlativo AS correlativo,
            tb.id AS ticket_numero,
            tb.correlativo AS ticket_correlativo,
            tb.created_at AS fecha_impresion,

            CASE WHEN du.unidad_id IS NOT NULL THEN veh_unidad.placa ELSE vh.placa END AS placa,

            lot.tipo_producto,
            lot.tipo_mineral,

            gui.guia_remitente AS guia_remision,

            pr.ruc AS ruc_proveedor,
            pr.razon_social AS proveedor,

            CASE WHEN du.unidad_id IS NOT NULL THEN
                CONCAT(COALESCE(cnd_unidad.apellido, ''), ' ', COALESCE(cnd_unidad.nombre, ''))
            ELSE
                CONCAT(COALESCE(cnd.apellido, ''), ' ', COALESCE(cnd.nombre, ''))
            END AS conductor,
            CASE WHEN du.unidad_id IS NOT NULL THEN cnd_unidad.numero_licencia ELSE cnd.numero_licencia END AS licencia_conductor,

            CASE WHEN du.unidad_id IS NOT NULL THEN emp_unidad.razon_social ELSE emp.razon_social END AS empresa_transporte,

            CASE WHEN gui.sin_guia_transportista = 1 OR gui.guia_transportista IS NULL OR gui.guia_transportista = '' THEN NULL ELSE gui.guia_transportista END AS guia_transporte,

            -- sucursal (Destino)
            sc.nombre AS nombre_sucursal,
            sc.direccion AS direccion_sucursal,
            dep_sc.nombre AS departamento_sucursal,
            prv_sc.nombre AS provincia_sucursal,
            dis_sc.nombre AS distrito_sucursal,

            -- ORIGEN: concesion de la guia; si la guia no trae, usar la del proveedor
            cns_origen.nombre          AS nombre_concesion,
            cns_origen.codigo_reinfo   AS codigo_reinfo_concesion,
            dep_cori.nombre            AS departamento_concesion,
            prv_cori.nombre            AS provincia_concesion,
            dis_cori.nombre            AS distrito_concesion,
            zo.nombre AS zona_origen_nombre,

            -- observaciones
            NULL AS observacion_peso_inicial,
            NULL AS observacion_peso_final,

            -- pesos y sus fechas (priorizar distribucion_detalle si existe pesaje, sino caer al lote)
            COALESCE(ddt.peso_bruto, lot.peso_inicial) AS peso_bruto,
            COALESCE(ddt.fecha_hora_peso_bruto, lot.fecha_hora_peso_inicial) AS fecha_hora_peso_bruto,
            COALESCE(ddt.peso_tara, lot.peso_final) AS peso_tara,
            COALESCE(ddt.fecha_hora_peso_tara, lot.fecha_hora_peso_final) AS fecha_hora_peso_tara,
            COALESCE(ddt.peso_neto, lot.peso_neto) AS peso_neto,

            -- Datos de distribución (última distribución activa del lote)
            desp_info.despacho_correlativo AS despacho_correlativo,
            desp_info.planta_destino_nombre AS planta_destino_nombre,

            -- operador
            CONCAT(COALESCE(eml.apellido, ''), ' ', COALESCE(eml.nombre, '')) AS operador,
            eml.dni AS dni_operador,
            cr.nombre AS cargo_operador

        FROM lote_mineral lot
        LEFT JOIN ticket_balanza tb ON tb.id = lot.id_ticket_balanza
        LEFT JOIN lote_guia ltg ON ltg.id_lote_mineral = lot.id
        LEFT JOIN guia_primer_tramo gui ON gui.id = ltg.id_guia_primer_tramo
        LEFT JOIN recepcion_unidad rec ON rec.id = lot.id_recepcion_unidad
        LEFT JOIN vehiculo vh ON vh.id = COALESCE(gui.id_vehiculo, rec.id_vehiculo)
        LEFT JOIN proveedor pr ON pr.id = COALESCE(gui.id_proveedor, lot.id_proveedor_minero)
        LEFT JOIN conductor cnd ON cnd.id = COALESCE(gui.id_conductor, rec.id_conductor)
        LEFT JOIN empresa_transporte emp ON emp.id = COALESCE(gui.id_empresa_transporte, rec.id_empresa_transporte)
        LEFT JOIN sucursal sc ON sc.id = COALESCE(gui.id_sucursal, rec.id_sucursal)
        LEFT JOIN departamento dep_sc ON dep_sc.id = sc.id_departamento
        LEFT JOIN provincia prv_sc ON prv_sc.id = sc.id_provincia
        LEFT JOIN distrito dis_sc ON dis_sc.id = sc.id_distrito
            LEFT JOIN concesion_proveedor cp
                ON cp.id_proveedor = pr.id
            LEFT JOIN concesion cns_origen
                ON cns_origen.id = COALESCE(
                    gui.id_concesion,
                    (
                        SELECT cp2.id_concesion
                        FROM concesion_proveedor cp2
                        WHERE cp2.id_proveedor = pr.id
                        ORDER BY cp2.id ASC
                        LIMIT 1
                    )
                )
            LEFT JOIN departamento dep_cori ON dep_cori.id = cns_origen.id_departamento
            LEFT JOIN provincia    prv_cori ON prv_cori.id = cns_origen.id_provincia
            LEFT JOIN distrito     dis_cori ON dis_cori.id = cns_origen.id_distrito
            LEFT JOIN zona_origen zo ON zo.id = lot.id_zona_origen
        -- Último pesaje con ticket del lote (de distribución si existe)
        LEFT JOIN (
            SELECT dd.id_lote_mineral, ddt.peso_tara, ddt.fecha_hora_peso_tara, ddt.peso_bruto, ddt.fecha_hora_peso_bruto, ddt.peso_neto
            FROM distribucion_detalle ddt
            INNER JOIN despacho_detalle dd ON dd.id = ddt.id_despacho_detalle
            WHERE dd.id_lote_mineral = ? AND ddt.id_ticket_balanza IS NOT NULL
            ORDER BY ddt.id DESC
            LIMIT 1
        ) ddt ON ddt.id_lote_mineral = lot.id
        -- Última distribución activa del lote (para contexto de despacho)
        LEFT JOIN (
            SELECT
                dd.id_lote_mineral,
                d.correlativo AS despacho_correlativo,
                pd.razon_social AS planta_destino_nombre
            FROM distribucion_detalle ddt
            INNER JOIN despacho_detalle dd ON dd.id = ddt.id_despacho_detalle
            INNER JOIN distribucion di ON di.id = ddt.id_distribucion
            INNER JOIN despacho d ON d.id = di.id_despacho
            LEFT JOIN planta_destino pd ON pd.id = d.id_planta_destino
            WHERE dd.id_lote_mineral = ?
            ORDER BY di.created_at DESC, ddt.id DESC
            LIMIT 1
        ) desp_info ON desp_info.id_lote_mineral = lot.id
        -- Unidad del despacho (cuando el pesaje fue vía distribución): lleva la placa,
        -- transportista y conductor que el usuario ingresó al pesar, no los del lote original.
        LEFT JOIN (
            SELECT
                dd.id_lote_mineral,
                ru_unidad.id AS unidad_id,
                ru_unidad.id_vehiculo AS unidad_vehiculo_id,
                ru_unidad.id_empresa_transporte AS unidad_emp_trans_id,
                ru_unidad.id_conductor AS unidad_conductor_id
            FROM distribucion_detalle ddt_unidad
            INNER JOIN distribucion di_unidad ON di_unidad.id = ddt_unidad.id_distribucion
            INNER JOIN despacho_detalle dd ON dd.id = ddt_unidad.id_despacho_detalle
            INNER JOIN recepcion_unidad ru_unidad ON ru_unidad.id_distribucion = di_unidad.id
            WHERE dd.id_lote_mineral = ?
              AND ddt_unidad.id_ticket_balanza IS NOT NULL
            ORDER BY ddt_unidad.id DESC
            LIMIT 1
        ) du ON du.id_lote_mineral = lot.id
        LEFT JOIN vehiculo veh_unidad ON veh_unidad.id = du.unidad_vehiculo_id
        LEFT JOIN empresa_transporte emp_unidad ON emp_unidad.id = du.unidad_emp_trans_id
        LEFT JOIN conductor cnd_unidad ON cnd_unidad.id = du.unidad_conductor_id
        LEFT JOIN empleado eml ON eml.id = lot.id_empleado_registro
        LEFT JOIN cargo cr ON cr.id = eml.id_cargo
        WHERE lot.id = ?
        LIMIT 1
        ";

        $item = DB::selectOne($sql, [$loteId, $loteId, $loteId, $loteId]);
        if ($item) {
            $item->peso_bruto = $item->peso_bruto !== null ? (float) $item->peso_bruto : null;
            $item->peso_tara = $item->peso_tara !== null ? (float) $item->peso_tara : null;
            $item->peso_neto = $item->peso_neto !== null ? (float) $item->peso_neto : null;
            $item->despacho_correlativo = $item->despacho_correlativo !== null ? (string) $item->despacho_correlativo : null;
            $item->planta_destino_nombre = $item->planta_destino_nombre !== null ? (string) $item->planta_destino_nombre : null;

            return (array) $item;
        }

        return null;
    }

    /**
     * Obtener la información completa para el Ticket de Balanza en formato PDF partiendo
     * de una `distribucion_detalle` (filas del Bloque B del Resumen de Balanza).
     *
     * Funciona tanto cuando el despacho_detalle proviene de un lote_mineral como de un
     * blending. El `correlativo` mostrado es el del origen (lote o blending).
     */
    public static function get_ticket_balanza_info_by_distribucion_detalle(int $idDistribucionDetalle)
    {
        $sql = "
        SELECT
            ddt.id AS id_distribucion_detalle,

            COALESCE(lm.id, b.id)                    AS id_lote,
            COALESCE(lm.correlativo, b.correlativo)  AS correlativo,
            CASE WHEN lm.id IS NOT NULL THEN 'LOTE' ELSE 'BLENDING' END AS origen_tipo,

            tb.id AS ticket_numero,
            tb.correlativo AS ticket_correlativo,
            tb.created_at AS fecha_impresion,

            veh.placa AS placa,

            lm.tipo_producto,
            lm.tipo_mineral,

            gui.guia_remitente AS guia_remision,

            pr.ruc AS ruc_proveedor,
            pr.razon_social AS proveedor,

            CONCAT(COALESCE(cnd.apellido, ''), ' ', COALESCE(cnd.nombre, '')) AS conductor,
            cnd.numero_licencia AS licencia_conductor,

            emp.razon_social AS empresa_transporte,

            CASE WHEN gui.sin_guia_transportista = 1 OR gui.guia_transportista IS NULL OR gui.guia_transportista = '' THEN NULL ELSE gui.guia_transportista END AS guia_transporte,

            sc.nombre AS nombre_sucursal,
            sc.direccion AS direccion_sucursal,
            dep_sc.nombre AS departamento_sucursal,
            prv_sc.nombre AS provincia_sucursal,
            dis_sc.nombre AS distrito_sucursal,

            cns_origen.nombre          AS nombre_concesion,
            cns_origen.codigo_reinfo   AS codigo_reinfo_concesion,
            dep_cori.nombre            AS departamento_concesion,
            prv_cori.nombre            AS provincia_concesion,
            dis_cori.nombre            AS distrito_concesion,
            zo.nombre AS zona_origen_nombre,

            NULL AS observacion_peso_inicial,
            NULL AS observacion_peso_final,

            ddt.peso_bruto,
            ddt.fecha_hora_peso_bruto,
            ddt.peso_tara,
            ddt.fecha_hora_peso_tara,
            ddt.peso_neto,

            d.correlativo AS despacho_correlativo,
            pd.razon_social AS planta_destino_nombre,

            CONCAT(COALESCE(eml.apellido, ''), ' ', COALESCE(eml.nombre, '')) AS operador,
            eml.dni AS dni_operador,
            cr.nombre AS cargo_operador,

            ddt.numero_particion

        FROM distribucion_detalle ddt
        INNER JOIN ticket_balanza tb ON tb.id = ddt.id_ticket_balanza
        INNER JOIN despacho_detalle dd ON dd.id = ddt.id_despacho_detalle
        LEFT JOIN lote_mineral lm ON lm.id = dd.id_lote_mineral
        LEFT JOIN blending b ON b.id = dd.id_blending
        LEFT JOIN lote_guia ltg ON ltg.id_lote_mineral = lm.id
        LEFT JOIN guia_primer_tramo gui ON gui.id = ltg.id_guia_primer_tramo
        INNER JOIN distribucion di ON di.id = ddt.id_distribucion
        INNER JOIN despacho d ON d.id = di.id_despacho
        LEFT JOIN planta_destino pd ON pd.id = d.id_planta_destino
        INNER JOIN recepcion_unidad rec ON rec.id_distribucion = di.id
        LEFT JOIN vehiculo veh ON veh.id = rec.id_vehiculo
        LEFT JOIN proveedor pr ON pr.id = COALESCE(gui.id_proveedor, lm.id_proveedor_minero)
        LEFT JOIN conductor cnd ON cnd.id = COALESCE(gui.id_conductor, rec.id_conductor)
        LEFT JOIN empresa_transporte emp ON emp.id = COALESCE(gui.id_empresa_transporte, rec.id_empresa_transporte)
        LEFT JOIN sucursal sc ON sc.id = COALESCE(gui.id_sucursal, rec.id_sucursal)
        LEFT JOIN departamento dep_sc ON dep_sc.id = sc.id_departamento
        LEFT JOIN provincia prv_sc ON prv_sc.id = sc.id_provincia
        LEFT JOIN distrito dis_sc ON dis_sc.id = sc.id_distrito
        LEFT JOIN concesion_proveedor cp ON cp.id_proveedor = pr.id
        LEFT JOIN concesion cns_origen ON cns_origen.id = COALESCE(
            gui.id_concesion,
            (
                SELECT cp2.id_concesion
                FROM concesion_proveedor cp2
                WHERE cp2.id_proveedor = pr.id
                ORDER BY cp2.id ASC
                LIMIT 1
            )
        )
        LEFT JOIN departamento dep_cori ON dep_cori.id = cns_origen.id_departamento
        LEFT JOIN provincia    prv_cori ON prv_cori.id = cns_origen.id_provincia
        LEFT JOIN distrito     dis_cori ON dis_cori.id = cns_origen.id_distrito
        LEFT JOIN zona_origen zo ON zo.id = lm.id_zona_origen
        LEFT JOIN empleado eml ON eml.id = rec.id_empleado_recepcion
        LEFT JOIN cargo cr ON cr.id = eml.id_cargo
        WHERE ddt.id = ?
        LIMIT 1
        ";

        $item = DB::selectOne($sql, [$idDistribucionDetalle]);
        if ($item) {
            $item->peso_bruto = $item->peso_bruto !== null ? (float) $item->peso_bruto : null;
            $item->peso_tara = $item->peso_tara !== null ? (float) $item->peso_tara : null;
            $item->peso_neto = $item->peso_neto !== null ? (float) $item->peso_neto : null;
            $item->despacho_correlativo = $item->despacho_correlativo !== null ? (string) $item->despacho_correlativo : null;
            $item->planta_destino_nombre = $item->planta_destino_nombre !== null ? (string) $item->planta_destino_nombre : null;

            return (array) $item;
        }

        return null;
    }

    /**
     * Listar las particiones activas de un lote (incluye JOIN con ticket_balanza, recepcion_unidad
     * y lote_mineral padre para hidratar los campos no-peso que la UI necesita pre-cargar).
     *
     * @return array<int, object>
     */
    public static function get_particiones_by_lote(int $idLote): array
    {
        $sql = '
        SELECT
            p.id,
            p.id_lote_mineral,
            p.id_ticket_balanza,
            tb.correlativo AS ticket_correlativo,
            p.id_recepcion_unidad,
            ru.estado_pesaje AS recepcion_estado_pesaje,
            v.placa AS vehiculo_placa,
            p.correlativo,
            p.particion,
            p.peso_inicial,
            p.fecha_hora_peso_inicial,
            p.peso_final,
            p.fecha_hora_peso_final,
            p.peso_neto,
            p.estado,
            p.es_bloqueado,
            p.esta_validado,
            p.evidencias,
            -- Campos heredados del lote padre (para que el modal los muestre sin fetch extra)
            lm.id_proveedor_minero AS lote_id_proveedor_minero,
            pr.razon_social AS lote_proveedor_nombre,
            lm.id_zona_origen AS lote_id_zona_origen,
            zo.nombre AS lote_zona_origen_nombre,
            lm.numero_contacto AS lote_numero_contacto,
            lm.tipo_producto AS lote_tipo_producto,
            lm.tipo_mineral AS lote_tipo_mineral,
            lm.correlativo AS lote_correlativo
        FROM particion_lote_mineral p
        LEFT JOIN ticket_balanza tb ON tb.id = p.id_ticket_balanza
        LEFT JOIN recepcion_unidad ru ON ru.id = p.id_recepcion_unidad
        LEFT JOIN vehiculo v ON v.id = ru.id_vehiculo
        LEFT JOIN lote_mineral lm ON lm.id = p.id_lote_mineral
        LEFT JOIN proveedor pr ON pr.id = lm.id_proveedor_minero
        LEFT JOIN zona_origen zo ON zo.id = lm.id_zona_origen
        WHERE p.id_lote_mineral = :id_lote
          AND p.estado = "Activo"
        ORDER BY p.particion ASC
        ';

        $rows = DB::select($sql, ['id_lote' => $idLote]);

        foreach ($rows as $r) {
            $r->id = (int) $r->id;
            $r->id_lote_mineral = (int) $r->id_lote_mineral;
            $r->id_ticket_balanza = $r->id_ticket_balanza !== null ? (int) $r->id_ticket_balanza : null;
            $r->id_recepcion_unidad = $r->id_recepcion_unidad !== null ? (int) $r->id_recepcion_unidad : null;
            $r->recepcion_estado_pesaje = $r->recepcion_estado_pesaje !== null ? (string) $r->recepcion_estado_pesaje : null;
            $r->vehiculo_placa = $r->vehiculo_placa !== null ? (string) $r->vehiculo_placa : null;
            $r->ticket_correlativo = $r->ticket_correlativo !== null ? (string) $r->ticket_correlativo : null;
            $r->peso_inicial = $r->peso_inicial !== null ? (float) $r->peso_inicial : null;
            $r->peso_final = $r->peso_final !== null ? (float) $r->peso_final : null;
            $r->peso_neto = $r->peso_neto !== null ? (float) $r->peso_neto : null;
            $r->es_bloqueado = (bool) $r->es_bloqueado;
            $r->esta_validado = (bool) $r->esta_validado;
            if (isset($r->evidencias) && is_string($r->evidencias)) {
                $r->evidencias = json_decode($r->evidencias, true) ?? [];
            } else {
                $r->evidencias = $r->evidencias ?? [];
            }
            // Aplanar campos heredados del padre en el mismo objeto para que la UI los use directo.
            $r->id_proveedor_minero = $r->lote_id_proveedor_minero !== null ? (int) $r->lote_id_proveedor_minero : null;
            $r->proveedor_nombre = $r->lote_proveedor_nombre !== null ? (string) $r->lote_proveedor_nombre : null;
            $r->id_zona_origen = $r->lote_id_zona_origen !== null ? (int) $r->lote_id_zona_origen : null;
            $r->zona_origen_nombre = $r->lote_zona_origen_nombre !== null ? (string) $r->lote_zona_origen_nombre : null;
            $r->numero_contacto = $r->lote_numero_contacto !== null ? (string) $r->lote_numero_contacto : null;
            $r->tipo_producto = $r->lote_tipo_producto !== null ? (string) $r->lote_tipo_producto : null;
            $r->tipo_mineral = $r->lote_tipo_mineral !== null ? (string) $r->lote_tipo_mineral : null;
            $r->lote_correlativo = $r->lote_correlativo !== null ? (string) $r->lote_correlativo : null;
        }

        return $rows;
    }

    /**
     * Obtener una partición específica por su ID con JOIN a lote padre y unidad.
     */
    public static function get_particion_by_id(int $idParticion)
    {
        $sql = '
        SELECT
            p.id,
            p.id_lote_mineral,
            p.id_ticket_balanza,
            tb.correlativo AS ticket_correlativo,
            p.id_recepcion_unidad,
            p.correlativo,
            p.particion,
            p.peso_inicial,
            p.fecha_hora_peso_inicial,
            p.peso_final,
            p.fecha_hora_peso_final,
            p.peso_neto,
            p.estado,
            p.es_bloqueado,
            p.esta_validado,
            p.evidencias,
            -- Datos heredados del lote padre (para hidratar el modal)
            lm.id_proveedor_minero,
            lm.proveedor_legacy AS proveedor_nombre,
            lm.id_zona_origen,
            lm.zona_origen_legacy AS zona_origen_nombre,
            lm.numero_contacto,
            lm.tipo_producto,
            lm.tipo_mineral
        FROM particion_lote_mineral p
        LEFT JOIN ticket_balanza tb ON tb.id = p.id_ticket_balanza
        LEFT JOIN (
            SELECT
                l.id, l.id_proveedor_minero, p.razon_social AS proveedor_legacy,
                l.id_zona_origen, z.nombre AS zona_origen_legacy,
                l.numero_contacto, l.tipo_producto, l.tipo_mineral
            FROM lote_mineral l
            LEFT JOIN proveedor p ON p.id = l.id_proveedor_minero
            LEFT JOIN zona_origen z ON z.id = l.id_zona_origen
        ) lm ON lm.id = p.id_lote_mineral
        WHERE p.id = :id
        LIMIT 1
        ';

        $item = DB::selectOne($sql, ['id' => $idParticion]);
        if (! $item) {
            return null;
        }

        $item->id = (int) $item->id;
        $item->id_lote_mineral = (int) $item->id_lote_mineral;
        $item->id_ticket_balanza = $item->id_ticket_balanza !== null ? (int) $item->id_ticket_balanza : null;
        $item->id_recepcion_unidad = $item->id_recepcion_unidad !== null ? (int) $item->id_recepcion_unidad : null;
        $item->peso_inicial = $item->peso_inicial !== null ? (float) $item->peso_inicial : null;
        $item->peso_final = $item->peso_final !== null ? (float) $item->peso_final : null;
        $item->peso_neto = $item->peso_neto !== null ? (float) $item->peso_neto : null;
        $item->es_bloqueado = (bool) $item->es_bloqueado;
        $item->esta_validado = (bool) $item->esta_validado;
        $item->id_proveedor_minero = $item->id_proveedor_minero !== null ? (int) $item->id_proveedor_minero : null;
        $item->id_zona_origen = $item->id_zona_origen !== null ? (int) $item->id_zona_origen : null;
        $item->ticket_correlativo = $item->ticket_correlativo !== null ? (string) $item->ticket_correlativo : null;
        if (isset($item->evidencias) && is_string($item->evidencias)) {
            $item->evidencias = json_decode($item->evidencias, true) ?? [];
        } else {
            $item->evidencias = $item->evidencias ?? [];
        }

        return $item;
    }

    /**
     * Query SQL DEDICADA para imprimir el Ticket de Balanza de una PARTICIÓN.
     *
     * A diferencia de `get_ticket_balanza_info(int $loteId)` (que pivota desde
     * `lote_mineral` y devuelve datos del lote padre), esta función pivota desde
     * `particion_lote_mineral` y devuelve:
     *  - Ticket propio de la PARTICIÓN (`tb.id = plm.id_ticket_balanza`).
     *  - Datos de la UNIDAD DESTINO de la partición (`rec.id = plm.id_recepcion_unidad`)
     *    para placa, conductor, transportista, sucursal y operador.
     *  - Proveedor con cascada: `lot.id_proveedor_minero` (lote padre, prioridad)
     *    → `gui.id_proveedor` (guía) → `rec.id_proveedor_minero` (unidad destino,
     *    fallback). Garantiza que TODAS las particiones del mismo lote padre
     *    muestren el mismo proveedor en su ticket, sin importar la unidad donde
     *    viva cada partición.
     *
     * Patrón espejo de `ValidacionDistribucionData::get_ticket_balanza_particion`
     * para que cada módulo imprima correctamente para su tipo de partición.
     *
     * @return array<string, mixed>|null
     */
    public static function get_ticket_balanza_info_particion(int $idParticion): ?array
    {
        $sql = "
        SELECT
            plm.id_lote_mineral AS id_lote,
            plm.correlativo AS correlativo,
            tb.id AS ticket_numero,
            tb.correlativo AS ticket_correlativo,
            tb.created_at AS fecha_impresion,

            vh.placa AS placa,

            lot.tipo_producto,
            lot.tipo_mineral,

            gui.guia_remitente AS guia_remision,

            pr.ruc AS ruc_proveedor,
            pr.razon_social AS proveedor,

            CONCAT(COALESCE(cnd.apellido, ''), ' ', COALESCE(cnd.nombre, '')) AS conductor,
            cnd.numero_licencia AS licencia_conductor,

            emp.razon_social AS empresa_transporte,

            CASE WHEN gui.sin_guia_transportista = 1 OR gui.guia_transportista IS NULL OR gui.guia_transportista = '' THEN NULL ELSE gui.guia_transportista END AS guia_transporte,

            sc.nombre AS nombre_sucursal,
            sc.direccion AS direccion_sucursal,
            dep_sc.nombre AS departamento_sucursal,
            prv_sc.nombre AS provincia_sucursal,
            dis_sc.nombre AS distrito_sucursal,

            cns_origen.nombre          AS nombre_concesion,
            cns_origen.codigo_reinfo   AS codigo_reinfo_concesion,
            dep_cori.nombre            AS departamento_concesion,
            prv_cori.nombre            AS provincia_concesion,
            dis_cori.nombre            AS distrito_concesion,
            zo.nombre AS zona_origen_nombre,

            NULL AS observacion_peso_inicial,
            NULL AS observacion_peso_final,

            plm.fecha_hora_peso_inicial,
            plm.peso_inicial AS peso_bruto,
            plm.fecha_hora_peso_final,
            plm.peso_final AS peso_tara,
            plm.peso_neto AS peso_neto,

            CONCAT(COALESCE(eml.apellido, ''), ' ', COALESCE(eml.nombre, '')) AS operador,
            eml.dni AS dni_operador,
            cr.nombre AS cargo_operador

        FROM particion_lote_mineral plm
        INNER JOIN lote_mineral lot ON lot.id = plm.id_lote_mineral
        LEFT JOIN ticket_balanza tb ON tb.id = plm.id_ticket_balanza
        LEFT JOIN lote_guia ltg ON ltg.id_lote_mineral = lot.id
        LEFT JOIN guia_primer_tramo gui ON gui.id = ltg.id_guia_primer_tramo
        LEFT JOIN recepcion_unidad rec ON rec.id = plm.id_recepcion_unidad
        LEFT JOIN vehiculo vh ON vh.id = COALESCE(rec.id_vehiculo, gui.id_vehiculo)
        LEFT JOIN proveedor pr ON pr.id = COALESCE(lot.id_proveedor_minero, gui.id_proveedor, rec.id_proveedor_minero)
        LEFT JOIN conductor cnd ON cnd.id = COALESCE(rec.id_conductor, gui.id_conductor)
        LEFT JOIN empresa_transporte emp ON emp.id = COALESCE(rec.id_empresa_transporte, gui.id_empresa_transporte)
        LEFT JOIN sucursal sc ON sc.id = COALESCE(rec.id_sucursal, gui.id_sucursal)
        LEFT JOIN departamento dep_sc ON dep_sc.id = sc.id_departamento
        LEFT JOIN provincia prv_sc ON prv_sc.id = sc.id_provincia
        LEFT JOIN distrito dis_sc ON dis_sc.id = sc.id_distrito
        LEFT JOIN concesion cns_origen ON cns_origen.id = gui.id_concesion
        LEFT JOIN departamento dep_cori ON dep_cori.id = cns_origen.id_departamento
        LEFT JOIN provincia    prv_cori ON prv_cori.id = cns_origen.id_provincia
        LEFT JOIN distrito     dis_cori ON dis_cori.id = cns_origen.id_distrito
        LEFT JOIN zona_origen zo ON zo.id = lot.id_zona_origen
        LEFT JOIN empleado eml ON eml.id = rec.id_empleado_recepcion
        LEFT JOIN cargo cr ON cr.id = eml.id_cargo
        WHERE plm.id = :id_particion
        LIMIT 1
        ";

        $item = DB::selectOne($sql, ['id_particion' => $idParticion]);
        if (! $item) {
            return null;
        }

        $item->peso_bruto = $item->peso_bruto !== null ? (float) $item->peso_bruto : null;
        $item->peso_tara = $item->peso_tara !== null ? (float) $item->peso_tara : null;
        $item->peso_neto = $item->peso_neto !== null ? (float) $item->peso_neto : null;

        return (array) $item;
    }

    /**
     * Verificar si ya existe una partición activa del lote en la unidad indicada.
     */
    public static function existe_particion_en_unidad(int $idLote, int $idRecepcionUnidad): bool
    {
        $count = DB::table('particion_lote_mineral')
            ->where('id_lote_mineral', $idLote)
            ->where('id_recepcion_unidad', $idRecepcionUnidad)
            ->where('estado', 'Activo')
            ->count();

        return $count > 0;
    }

    /**
     * Suma de peso_neto de particiones activas de un lote.
     */
    public static function sum_peso_neto_particiones(int $idLote): float
    {
        $sum = DB::table('particion_lote_mineral')
            ->where('id_lote_mineral', $idLote)
            ->where('estado', 'Activo')
            ->sum('peso_neto');

        return $sum !== null ? (float) $sum : 0.0;
    }

    /**
     * Cantidad de particiones activas del lote SIN peso_final registrado.
     */
    public static function count_particiones_sin_peso_final(int $idLote): int
    {
        return DB::table('particion_lote_mineral')
            ->where('id_lote_mineral', $idLote)
            ->where('estado', 'Activo')
            ->whereNull('peso_final')
            ->count();
    }

    /**
     * Cantidad de particiones activas del lote (para UI de finalizar).
     */
    public static function count_particiones_activas(int $idLote): int
    {
        return DB::table('particion_lote_mineral')
            ->where('id_lote_mineral', $idLote)
            ->where('estado', 'Activo')
            ->count();
    }

    /**
     * Cantidad de particiones activas que viven en una unidad de recepción
     * específica. Usado por `cerrar_proceso` para exigir que la unidad tenga
     * al menos una partición cuando no hay lotes regulares.
     */
    public static function count_particiones_activas_by_unidad(int $idRecepcionUnidad): int
    {
        return DB::table('particion_lote_mineral')
            ->where('id_recepcion_unidad', $idRecepcionUnidad)
            ->where('estado', EstadoBase::Activo->value)
            ->count();
    }

    /**
     * Devuelve, para cada partición activa del lote padre, el estado de pesaje de su
     * unidad huésped (`recepcion_unidad.estado_pesaje`). Una partición cuyo
     * `id_recepcion_unidad` es NULL o apunta a una unidad inexistente se reporta
     * con `estado_pesaje = null`.
     *
     * Estructura de cada fila:
     *   { id_particion, particion, id_recepcion_unidad, estado_pesaje }
     *
     * Usado por `finalizar_particion_lote` para exigir que TODAS las particiones
     * vivan en unidades con `estado_pesaje = 'Pesado'`.
     */
    public static function get_particiones_estado_pesaje_unidades(int $idLote): array
    {
        $sql = '
        SELECT
            p.id              AS id_particion,
            p.particion      AS particion,
            p.id_recepcion_unidad,
            ru.estado_pesaje AS estado_pesaje
        FROM particion_lote_mineral p
        LEFT JOIN recepcion_unidad ru ON ru.id = p.id_recepcion_unidad
        WHERE p.id_lote_mineral = :id_lote
          AND p.estado = "Activo"
        ORDER BY p.particion ASC
        ';

        $rows = DB::select($sql, ['id_lote' => $idLote]);

        return array_map(static function ($r) {
            return [
                'id_particion' => (int) $r->id_particion,
                'particion' => (string) $r->particion,
                'id_recepcion_unidad' => $r->id_recepcion_unidad !== null ? (int) $r->id_recepcion_unidad : null,
                'estado_pesaje' => $r->estado_pesaje !== null ? (string) $r->estado_pesaje : null,
            ];
        }, $rows);
    }

    /**
     * Lotes padre particionados desde balanza con particiones activas (para el header global).
     * Filtra por sucursal buscando a través de las recepciones de sus particiones activas.
     *
     * Usa EXISTS en lugar de INNER JOIN para evitar el producto cartesiano (un padre con
     * N particiones generaba N filas, obligando a un SELECT DISTINCT que rompía el
     * ORDER BY por lm.created_at en MySQL).
     */
    public static function get_lotes_padre_particionados_by_sucursal(int $idSucursal): array
    {
        $sql = '
        SELECT
            lm.id,
            lm.correlativo,
            lm.numero_correlativo,
            lm.id_empresa,
            lm.particionado_desde_balanza,
            lm.particion_finalizada,
            lm.id_empleado_fin_particion,
            lm.fecha_hora_fin_particion,
            lm.tipo_producto,
            lm.tipo_mineral,
            lm.condicion_ingreso,
            lm.tiene_particion,
            lm.created_at,
            (
                SELECT COUNT(*)
                FROM particion_lote_mineral p
                WHERE p.id_lote_mineral = lm.id AND p.estado = "Activo"
            ) AS total_particiones,
            (
                SELECT COUNT(*)
                FROM particion_lote_mineral p
                WHERE p.id_lote_mineral = lm.id
                  AND p.estado = "Activo"
                  AND p.peso_final IS NULL
            ) AS particiones_sin_peso_final
        FROM lote_mineral lm
        WHERE lm.particionado_desde_balanza = 1
          AND (lm.estado IS NULL OR lm.estado != "Eliminado")
          AND EXISTS (
              SELECT 1
              FROM particion_lote_mineral p
              INNER JOIN recepcion_unidad ru ON ru.id = p.id_recepcion_unidad
              WHERE p.id_lote_mineral = lm.id
                AND p.estado = "Activo"
                AND ru.id_sucursal = :id_sucursal
          )
        ORDER BY lm.created_at DESC
        ';

        return DB::select($sql, ['id_sucursal' => $idSucursal]);
    }
}
