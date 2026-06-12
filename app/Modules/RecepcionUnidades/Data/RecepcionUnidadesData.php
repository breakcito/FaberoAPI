<?php

namespace App\Modules\RecepcionUnidades\Data;

use App\Models\RecepcionUnidad;
use Illuminate\Support\Facades\DB;

class RecepcionUnidadesData
{
    /**
     * Obtener lista de recepciones de unidades con filtros dinámicos.
     */
    public static function get_recepciones(array $filters = [])
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
            ru.evidencias,
            ru.observacion,
            ru.estado,
            ru.estado_salida,
            ru.fecha_hora_salida,
            ru.observacion_salida
        FROM
            recepcion_unidad ru
        INNER JOIN empleado emp_reg ON emp_reg.id = ru.id_empleado_registro
        INNER JOIN vehiculo v ON v.id = ru.id_vehiculo
        INNER JOIN empresa_transporte et ON et.id = ru.id_empresa_transporte
        INNER JOIN tipo_vehiculo tv ON tv.id = ru.id_tipo_vehiculo
        INNER JOIN conductor c ON c.id = ru.id_conductor
        WHERE 1 = 1
        ';

        $params = [];

        // Filtro por fecha de ingreso (Rango)
        if (!empty($filters['fecha_inicio'])) {
            $sql .= ' AND ru.fecha_hora_ingreso >= :fecha_inicio';
            $params['fecha_inicio'] = $filters['fecha_inicio'] . ' 00:00:00';
        }

        if (!empty($filters['fecha_fin'])) {
            $sql .= ' AND ru.fecha_hora_ingreso <= :fecha_fin';
            $params['fecha_fin'] = $filters['fecha_fin'] . ' 23:59:59';
        }

        // Filtro por número de placa
        if (!empty($filters['numero_placa'])) {
            $sql .= ' AND v.numero_placa LIKE :numero_placa';
            $params['numero_placa'] = '%' . $filters['numero_placa'] . '%';
        }

        // Filtro por serie de placa
        if (!empty($filters['serie_placa'])) {
            $sql .= ' AND v.serie_placa LIKE :serie_placa';
            $params['serie_placa'] = '%' . $filters['serie_placa'] . '%';
        }

        // Filtro por transportista (empresa de transporte)
        if (!empty($filters['id_empresa_transporte'])) {
            $sql .= ' AND ru.id_empresa_transporte = :id_empresa_transporte';
            $params['id_empresa_transporte'] = (int) $filters['id_empresa_transporte'];
        }

        // Filtro por condición de ingreso (tipo_ingreso)
        if (!empty($filters['tipo_ingreso'])) {
            $sql .= ' AND ru.tipo_ingreso = :tipo_ingreso';
            $params['tipo_ingreso'] = $filters['tipo_ingreso'];
        }

        $sql .= ' ORDER BY ru.fecha_hora_ingreso DESC;';

        $results = DB::select($sql, $params);

        // Decodificar la columna JSON de evidencias manualmente para que coincida con lo esperado por Eloquent
        foreach ($results as $item) {
            if (isset($item->evidencias)) {
                $item->evidencias = json_decode($item->evidencias, true) ?? [];
            }
        }

        return $results;
    }

    /**
     * Obtener recepción específica por su ID.
     */
    public static function get_recepcion_by_id(int $id)
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
            ru.evidencias,
            ru.observacion,
            ru.estado,
            ru.estado_salida,
            ru.fecha_hora_salida,
            ru.observacion_salida
        FROM
            recepcion_unidad ru
        INNER JOIN empleado emp_reg ON emp_reg.id = ru.id_empleado_registro
        INNER JOIN vehiculo v ON v.id = ru.id_vehiculo
        INNER JOIN empresa_transporte et ON et.id = ru.id_empresa_transporte
        INNER JOIN tipo_vehiculo tv ON tv.id = ru.id_tipo_vehiculo
        INNER JOIN conductor c ON c.id = ru.id_conductor
        WHERE ru.id = :id
        LIMIT 1;
        ';

        $item = DB::selectOne($sql, ['id' => $id]);

        if ($item && isset($item->evidencias)) {
            $item->evidencias = json_decode($item->evidencias, true) ?? [];
        }

        return $item ? (array) $item : null;
    }

    /**
     * Crear un registro de recepción.
     */
    public static function crear_recepcion(array $data): int
    {
        $recepcion = RecepcionUnidad::create([
            'id_empleado_registro' => $data['id_empleado_registro'],
            'id_vehiculo' => $data['id_vehiculo'],
            'id_empresa_transporte' => $data['id_empresa_transporte'],
            'id_tipo_vehiculo' => $data['id_tipo_vehiculo'],
            'id_conductor' => $data['id_conductor'],
            'tipo_ingreso' => $data['tipo_ingreso'],
            'tipo_carga' => $data['tipo_carga'],
            'segunda_placa' => $data['segunda_placa'] ?? null,
            'evidencias' => $data['evidencias'] ?? [],
            'observacion' => $data['observacion'] ?? null,
            'estado' => 'En Planta',
        ]);

        return $recepcion->id;
    }
}
