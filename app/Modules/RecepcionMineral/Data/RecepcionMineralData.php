<?php

namespace App\Modules\RecepcionMineral\Data;

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
            ru.id_empleado_registro,
            CONCAT(emp_reg.nombre, " ", emp_reg.apellido) AS empleado_registro_nombre,
            ru.id_vehiculo,
            v.numero_placa AS vehiculo_placa,
            v.serie_placa AS vehiculo_serie,
            ru.id_empresa_transporte,
            et.razon_social AS empresa_transporte_razon_social,
            ru.id_tipo_vehiculo,
            tv.nombre AS tipo_vehiculo_nombre,
            ru.id_conductor,
            CONCAT(c.nombre, " ", c.apellido) AS conductor_nombre_completo,
            c.dni AS conductor_dni,
            ru.tipo_ingreso,
            ru.tipo_carga,
            ru.segunda_placa,
            ru.fecha_hora_ingreso,
            ru.fecha_hora_salida,
            ru.fecha_hora_inicio_pesaje,
            ru.fecha_hora_final_pesaje,
            ru.evidencias,
            ru.observacion,
            ru.estado,
            ru.estado_salida,
            ru.estado_pesaje,
            ru.validacion_datos,
            ru.id_surcusal AS id_sucursal
        FROM
            recepcion_unidad ru
        INNER JOIN empleado emp_reg ON emp_reg.id = ru.id_empleado_registro
        LEFT JOIN vehiculo v ON v.id = ru.id_vehiculo
        LEFT JOIN empresa_transporte et ON et.id = ru.id_empresa_transporte
        LEFT JOIN tipo_vehiculo tv ON tv.id = ru.id_tipo_vehiculo
        LEFT JOIN conductor c ON c.id = ru.id_conductor
        WHERE ru.id_surcusal = :id_sucursal
          AND ru.estado = "En Planta"
        ';

        $params = ['id_sucursal' => (int) $filters['id_sucursal']];

        if (! empty($filters['estado_pesaje'])) {
            $sql .= ' AND ru.estado_pesaje = :estado_pesaje';
            $params['estado_pesaje'] = $filters['estado_pesaje'];
        } else {
            $sql .= ' AND ru.estado_pesaje IN ("Sin Pesar", "En Proceso")';
        }

        $sql .= ' ORDER BY ru.fecha_hora_ingreso DESC;';

        $results = DB::select($sql, $params);

        foreach ($results as $item) {
            if (isset($item->evidencias)) {
                $item->evidencias = json_decode($item->evidencias, true) ?? [];
            }
            if (isset($item->validacion_datos)) {
                $item->validacion_datos = json_decode($item->validacion_datos, true) ?? [];
            }
            // Obtener los lotes de esta recepción
            $item->lotes = self::get_lotes_by_recepcion($item->id);
        }

        return $results;
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
            lm.id_proveedor_minero,
            p.razon_social AS proveedor_nombre,
            p.telefono AS proveedor_telefono,
            lm.id_empleado_registro,
            CONCAT(emp.nombre, " ", emp.apellido) AS empleado_registro_nombre,
            lm.id_encargado_muestra,
            CONCAT(em.nombre, " ", em.apellido) AS encargado_nombre,
            lm.id_zona_origen,
            zo.nombre AS zona_origen_nombre,
            lm.correlativo,
            lm.numero_correlativo,
            lm.tipo_carga,
            lm.numero_contacto,
            lm.tipo_producto,
            lm.tipo_mineral,
            lm.condicion_ingreso,
            lm.log_cambios,
            lm.evidencias,
            lm.peso_inicial,
            lm.fecha_hora_peso_inicial,
            lm.observacion_peso_inicial,
            lm.peso_final,
            lm.fecha_hora_peso_final,
            lm.observacion_peso_final,
            lm.peso_neto,
            COALESCE(lm.id_vehiculo, ru.id_vehiculo) AS id_vehiculo,
            v_lote.numero_placa AS vehiculo_placa,
            v_lote.serie_placa AS vehiculo_serie,
            COALESCE(lm.id_empresa_transporte, ru.id_empresa_transporte) AS id_empresa_transporte,
            et_lote.razon_social AS empresa_transporte_razon_social,
            COALESCE(lm.id_tipo_vehiculo, ru.id_tipo_vehiculo) AS id_tipo_vehiculo,
            tv_lote.nombre AS tipo_vehiculo_nombre,
            COALESCE(lm.id_conductor, ru.id_conductor) AS id_conductor,
            CONCAT(c_lote.nombre, " ", c_lote.apellido) AS conductor_nombre_completo,
            c_lote.dni AS conductor_dni,
            lm.created_at
        FROM
            lote_mineral lm
        INNER JOIN recepcion_unidad ru ON ru.id = lm.id_recepcion_unidad
        INNER JOIN empleado emp ON emp.id = lm.id_empleado_registro
        LEFT JOIN proveedor p ON p.id = lm.id_proveedor_minero
        LEFT JOIN encargado_muestra em ON em.id = lm.id_encargado_muestra
        LEFT JOIN zona_origen zo ON zo.id = lm.id_zona_origen
        LEFT JOIN vehiculo v_lote ON v_lote.id = COALESCE(lm.id_vehiculo, ru.id_vehiculo)
        LEFT JOIN empresa_transporte et_lote ON et_lote.id = COALESCE(lm.id_empresa_transporte, ru.id_empresa_transporte)
        LEFT JOIN tipo_vehiculo tv_lote ON tv_lote.id = COALESCE(lm.id_tipo_vehiculo, ru.id_tipo_vehiculo)
        LEFT JOIN conductor c_lote ON c_lote.id = COALESCE(lm.id_conductor, ru.id_conductor)
        WHERE
            lm.id_recepcion_unidad = :recepcion_unidad_id
        ORDER BY lm.correlativo ASC
        ';

        $results = DB::select($sql, ['recepcion_unidad_id' => $recepcionUnidadId]);

        foreach ($results as $item) {
            if (isset($item->evidencias)) {
                $item->evidencias = json_decode($item->evidencias, true) ?? [];
            }
            $item->peso_inicial = $item->peso_inicial !== null ? (float) $item->peso_inicial : null;
            $item->peso_final = $item->peso_final !== null ? (float) $item->peso_final : null;
            $item->peso_neto = $item->peso_neto !== null ? (float) $item->peso_neto : null;
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
            lm.id_proveedor_minero,
            p.razon_social AS proveedor_nombre,
            p.telefono AS proveedor_telefono,
            lm.id_empleado_registro,
            CONCAT(emp.nombre, " ", emp.apellido) AS empleado_registro_nombre,
            lm.id_encargado_muestra,
            CONCAT(em.nombre, " ", em.apellido) AS encargado_nombre,
            lm.id_zona_origen,
            zo.nombre AS zona_origen_nombre,
            lm.correlativo,
            lm.numero_correlativo,
            lm.tipo_carga,
            lm.numero_contacto,
            lm.tipo_producto,
            lm.tipo_mineral,
            lm.condicion_ingreso,
            lm.log_cambios,
            lm.evidencias,
            lm.peso_inicial,
            lm.fecha_hora_peso_inicial,
            lm.observacion_peso_inicial,
            lm.peso_final,
            lm.fecha_hora_peso_final,
            lm.observacion_peso_final,
            lm.peso_neto,
            COALESCE(lm.id_vehiculo, ru.id_vehiculo) AS id_vehiculo,
            v_lote.numero_placa AS vehiculo_placa,
            v_lote.serie_placa AS vehiculo_serie,
            COALESCE(lm.id_empresa_transporte, ru.id_empresa_transporte) AS id_empresa_transporte,
            et_lote.razon_social AS empresa_transporte_razon_social,
            COALESCE(lm.id_tipo_vehiculo, ru.id_tipo_vehiculo) AS id_tipo_vehiculo,
            tv_lote.nombre AS tipo_vehiculo_nombre,
            COALESCE(lm.id_conductor, ru.id_conductor) AS id_conductor,
            CONCAT(c_lote.nombre, " ", c_lote.apellido) AS conductor_nombre_completo,
            c_lote.dni AS conductor_dni,
            c_lote.numero_licencia AS conductor_licencia,
            lm.created_at
        FROM
            lote_mineral lm
        INNER JOIN recepcion_unidad ru ON ru.id = lm.id_recepcion_unidad
        INNER JOIN empleado emp ON emp.id = lm.id_empleado_registro
        LEFT JOIN proveedor p ON p.id = lm.id_proveedor_minero
        LEFT JOIN encargado_muestra em ON em.id = lm.id_encargado_muestra
        LEFT JOIN zona_origen zo ON zo.id = lm.id_zona_origen
        LEFT JOIN vehiculo v_lote ON v_lote.id = COALESCE(lm.id_vehiculo, ru.id_vehiculo)
        LEFT JOIN empresa_transporte et_lote ON et_lote.id = COALESCE(lm.id_empresa_transporte, ru.id_empresa_transporte)
        LEFT JOIN tipo_vehiculo tv_lote ON tv_lote.id = COALESCE(lm.id_tipo_vehiculo, ru.id_tipo_vehiculo)
        LEFT JOIN conductor c_lote ON c_lote.id = COALESCE(lm.id_conductor, ru.id_conductor)
        WHERE
            lm.id = :id
        LIMIT 1
        ';

        $item = DB::selectOne($sql, ['id' => $id]);

        if ($item) {
            if (isset($item->evidencias)) {
                $item->evidencias = json_decode($item->evidencias, true) ?? [];
            }
            $item->peso_inicial = $item->peso_inicial !== null ? (float) $item->peso_inicial : null;
            $item->peso_final = $item->peso_final !== null ? (float) $item->peso_final : null;
            $item->peso_neto = $item->peso_neto !== null ? (float) $item->peso_neto : null;
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
            ru.id_empleado_registro,
            CONCAT(emp_reg.nombre, " ", emp_reg.apellido) AS empleado_registro_nombre,
            ru.id_vehiculo,
            v.numero_placa AS vehiculo_placa,
            v.serie_placa AS vehiculo_serie,
            ru.id_empresa_transporte,
            et.razon_social AS empresa_transporte_razon_social,
            ru.id_tipo_vehiculo,
            tv.nombre AS tipo_vehiculo_nombre,
            ru.id_conductor,
            CONCAT(c.nombre, " ", c.apellido) AS conductor_nombre_completo,
            c.dni AS conductor_dni,
            ru.tipo_ingreso,
            ru.tipo_carga,
            ru.segunda_placa,
            ru.fecha_hora_ingreso,
            ru.fecha_hora_salida,
            ru.fecha_hora_inicio_pesaje,
            ru.fecha_hora_final_pesaje,
            ru.evidencias,
            ru.observacion,
            ru.estado,
            ru.estado_salida,
            ru.estado_pesaje,
            ru.validacion_datos,
            ru.id_surcusal AS id_sucursal
        FROM
            recepcion_unidad ru
        INNER JOIN empleado emp_reg ON emp_reg.id = ru.id_empleado_registro
        LEFT JOIN vehiculo v ON v.id = ru.id_vehiculo
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
            if (isset($item->validacion_datos)) {
                $item->validacion_datos = json_decode($item->validacion_datos, true) ?? [];
            }
            $item->lotes = self::get_lotes_by_recepcion($id);

            return (array) $item;
        }

        return null;
    }

    /**
     * Obtener el resumen de balanza (lotes pesados y sus recepciones) con filtros aplicados
     */
    public static function get_resumen_balanza(array $filters): array
    {
        $sql = '
        SELECT
            lm.id AS id_lote,
            lm.id_recepcion_unidad,
            lm.correlativo AS lote_correlativo,
            lm.numero_correlativo AS lote_numero_correlativo,
            lm.tipo_carga AS lote_tipo_carga,
            lm.numero_contacto AS lote_numero_contacto,
            lm.tipo_producto AS lote_tipo_producto,
            lm.tipo_mineral AS lote_tipo_mineral,
            lm.peso_inicial,
            lm.fecha_hora_peso_inicial,
            lm.observacion_peso_inicial,
            lm.peso_final,
            lm.fecha_hora_peso_final,
            lm.observacion_peso_final,
            lm.peso_neto,
            lm.created_at AS lote_fecha_creacion,
            lm.evidencias AS lote_evidencias,
            lm.condicion_ingreso AS lote_condicion_ingreso,
            lm.log_cambios AS lote_log_cambios,
            
            ru.tipo_ingreso,
            ru.fecha_hora_ingreso,
            ru.fecha_hora_salida,
            ru.segunda_placa,
            ru.estado_pesaje,
            
            COALESCE(lm.id_vehiculo, ru.id_vehiculo) AS id_vehiculo,
            v.serie_placa AS vehiculo_serie,
            v.numero_placa AS vehiculo_placa,
            
            COALESCE(lm.id_empresa_transporte, ru.id_empresa_transporte) AS id_empresa_transporte,
            et.razon_social AS empresa_transporte_razon_social,
            
            COALESCE(lm.id_tipo_vehiculo, ru.id_tipo_vehiculo) AS id_tipo_vehiculo,
            tv.nombre AS tipo_vehiculo_nombre,
            
            p.id AS id_proveedor,
            p.razon_social AS proveedor_razon_social,
            
            zo.id AS id_zona_origen,
            zo.nombre AS zona_origen_nombre,
            
            em.id AS id_encargado_muestra,
            CONCAT(em.nombre, " ", em.apellido) AS encargado_muestra_nombre,
            
            COALESCE(lm.id_conductor, ru.id_conductor) AS id_conductor,
            CONCAT(c.nombre, " ", c.apellido) AS conductor_nombre_completo,
            c.dni AS conductor_dni,
            c.numero_licencia AS conductor_licencia,
            
            CONCAT(emp_reg.nombre, " ", emp_reg.apellido) AS empleado_registro_nombre
        FROM
            lote_mineral lm
        INNER JOIN recepcion_unidad ru ON ru.id = lm.id_recepcion_unidad
        LEFT JOIN vehiculo v ON v.id = COALESCE(lm.id_vehiculo, ru.id_vehiculo)
        LEFT JOIN empresa_transporte et ON et.id = COALESCE(lm.id_empresa_transporte, ru.id_empresa_transporte)
        LEFT JOIN tipo_vehiculo tv ON tv.id = COALESCE(lm.id_tipo_vehiculo, ru.id_tipo_vehiculo)
        LEFT JOIN proveedor p ON p.id = lm.id_proveedor_minero
        LEFT JOIN zona_origen zo ON zo.id = lm.id_zona_origen
        LEFT JOIN encargado_muestra em ON em.id = lm.id_encargado_muestra
        LEFT JOIN conductor c ON c.id = COALESCE(lm.id_conductor, ru.id_conductor)
        LEFT JOIN empleado emp_reg ON emp_reg.id = lm.id_empleado_registro
        WHERE
            ru.id_surcusal = :id_sucursal
            AND ru.estado_pesaje = :estado_pesaje
        ';

        $params = [
            'id_sucursal' => (int) $filters['id_sucursal'],
            'estado_pesaje' => EstadoPesaje::Pesado->value,
        ];

        if (! empty($filters['fecha_inicio'])) {
            $sql .= ' AND DATE(lm.created_at) >= :fecha_inicio';
            $params['fecha_inicio'] = $filters['fecha_inicio'];
        }

        if (! empty($filters['fecha_fin'])) {
            $sql .= ' AND DATE(lm.created_at) <= :fecha_fin';
            $params['fecha_fin'] = $filters['fecha_fin'];
        }

        if (! empty($filters['tipo_ingreso'])) {
            $sql .= ' AND ru.tipo_ingreso = :tipo_ingreso';
            $params['tipo_ingreso'] = $filters['tipo_ingreso'];
        }

        if (! empty($filters['placa'])) {
            $sql .= ' AND (v.numero_placa = :placa1 OR CONCAT(COALESCE(v.serie_placa, ""), "-", v.numero_placa) = :placa2 OR v.serie_placa = :placa3)';
            $params['placa1'] = $filters['placa'];
            $params['placa2'] = $filters['placa'];
            $params['placa3'] = $filters['placa'];
        }

        if (! empty($filters['id_lote_mineral'])) {
            $sql .= ' AND lm.id = :id_lote_mineral';
            $params['id_lote_mineral'] = (int) $filters['id_lote_mineral'];
        }

        if (! empty($filters['id_empresa_transporte'])) {
            $sql .= ' AND COALESCE(lm.id_empresa_transporte, ru.id_empresa_transporte) = :id_empresa_transporte';
            $params['id_empresa_transporte'] = (int) $filters['id_empresa_transporte'];
        }

        $sql .= ' ORDER BY lm.created_at DESC;';

        $results = DB::select($sql, $params);

        foreach ($results as $item) {
            if (isset($item->lote_evidencias)) {
                $item->lote_evidencias = json_decode($item->lote_evidencias, true) ?? [];
            }
            if (isset($item->lote_log_cambios)) {
                $item->lote_log_cambios = json_decode($item->lote_log_cambios, true) ?? [];
            }
            $item->peso_inicial = $item->peso_inicial !== null ? (float) $item->peso_inicial : null;
            $item->peso_final = $item->peso_final !== null ? (float) $item->peso_final : null;
            $item->peso_neto = $item->peso_neto !== null ? (float) $item->peso_neto : null;
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
        INNER JOIN recepcion_unidad ru ON ru.id = lm.id_recepcion_unidad
        WHERE ru.id_surcusal = :id_sucursal
        ORDER BY lm.correlativo DESC;
        ';
        $lotes = DB::select($lotesSql, ['id_sucursal' => $idSucursal]);

        // 2. Obtener vehículos de la sucursal (de recepcion o del lote)
        $vehiculosSql = '
        SELECT DISTINCT v.id, v.serie_placa, v.numero_placa
        FROM lote_mineral lm
        INNER JOIN recepcion_unidad ru ON ru.id = lm.id_recepcion_unidad
        INNER JOIN vehiculo v ON v.id = COALESCE(lm.id_vehiculo, ru.id_vehiculo)
        WHERE ru.id_surcusal = :id_sucursal
        ORDER BY v.numero_placa ASC;
        ';
        $vehiculos = DB::select($vehiculosSql, ['id_sucursal' => $idSucursal]);

        // 3. Obtener condiciones de ingreso de la sucursal
        $condicionesSql = '
        SELECT DISTINCT ru.tipo_ingreso
        FROM recepcion_unidad ru
        WHERE ru.id_surcusal = :id_sucursal
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
}
