<?php

namespace App\Modules\RecepcionVisitas\Data;

use App\Models\RecepcionVisita;
use Illuminate\Support\Facades\DB;

class RecepcionVisitasData
{
    /**
     * Obtener listado de recepciones de visitas con filtros.
     */
    public static function get_recepciones(array $filters = []): array
    {
        $sql = '
        SELECT
            rv.id,
            rv.id_empleado_registro,
            CONCAT(emp_reg.nombre, " ", emp_reg.apellido) AS empleado_registro_nombre,
            rv.id_empleado_contacto,
            CONCAT(emp_cont.nombre, " ", emp_cont.apellido) AS empleado_contacto_nombre,
            rv.id_motivo_ingreso,
            mi.nombre AS motivo_ingreso_nombre,
            rv.fecha_hora_ingreso,
            rv.observacion,
            rv.con_vehiculo,
            rv.serie_placa,
            rv.numero_placa
        FROM
            recepcion_visita rv
        LEFT JOIN empleado emp_reg ON emp_reg.id = rv.id_empleado_registro
        LEFT JOIN empleado emp_cont ON emp_cont.id = rv.id_empleado_contacto
        LEFT JOIN motivo_ingreso mi ON mi.id = rv.id_motivo_ingreso
        WHERE 1 = 1
        ';

        $params = [];

        // Filtro por fecha de ingreso (Rango)
        if (! empty($filters['fecha_inicio'])) {
            $sql .= ' AND rv.fecha_hora_ingreso >= :fecha_inicio';
            $params['fecha_inicio'] = $filters['fecha_inicio'].' 00:00:00';
        }

        if (! empty($filters['fecha_fin'])) {
            $sql .= ' AND rv.fecha_hora_ingreso <= :fecha_fin';
            $params['fecha_fin'] = $filters['fecha_fin'].' 23:59:59';
        }

        $sql .= ' ORDER BY rv.fecha_hora_ingreso DESC;';

        $results = DB::select($sql, $params);

        // Cargar visitantes de forma masiva para evitar consultas N+1
        $visitIds = array_column($results, 'id');
        if (! empty($visitIds)) {
            $idsString = implode(',', array_map('intval', $visitIds));
            $detalles = DB::select("
                SELECT
                    rvd.id_recepcion_visita,
                    rvd.id AS id_detalle,
                    rvd.id_visitante,
                    v.nombre AS visitante_nombre,
                    v.apellido AS visitante_apellido,
                    v.dni AS visitante_dni,
                    v.telefono AS visitante_telefono,
                    rvd.url_foto_documento,
                    rvd.fecha_hora_salida,
                    rvd.observacion_salida,
                    CASE rvd.estado WHEN 2 THEN 'Fuera de Planta' ELSE 'En Planta' END AS estado
                FROM
                    recepcion_visita_detalle rvd
                INNER JOIN visitante v ON v.id = rvd.id_visitante
                WHERE
                    rvd.id_recepcion_visita IN ($idsString)
            ");

            // Agrupar los detalles por ID de recepción de visita
            $detallesAgrupados = [];
            foreach ($detalles as $det) {
                $detallesAgrupados[$det->id_recepcion_visita][] = (array) $det;
            }

            // Asignar los visitantes a cada recepción y decodificar fotos del documento
            foreach ($results as $item) {
                $item->visitantes = $detallesAgrupados[$item->id] ?? [];
                foreach ($item->visitantes as &$v) {
                    $val = $v['url_foto_documento'] ?? null;
                    if (! empty($val)) {
                        $decoded = json_decode($val, true);
                        if (is_array($decoded)) {
                            $v['url_foto_documento'] = $decoded;
                        } else {
                            $v['url_foto_documento'] = [$val];
                        }
                    } else {
                        $v['url_foto_documento'] = [];
                    }
                }
            }
        }

        return array_map(function ($item) {
            return (array) $item;
        }, $results);
    }

    /**
     * Obtener una recepción de visita por ID.
     */
    public static function get_recepcion_by_id(int $id): ?array
    {
        $sql = '
        SELECT
            rv.id,
            rv.id_empleado_registro,
            CONCAT(emp_reg.nombre, " ", emp_reg.apellido) AS empleado_registro_nombre,
            rv.id_empleado_contacto,
            CONCAT(emp_cont.nombre, " ", emp_cont.apellido) AS empleado_contacto_nombre,
            rv.id_motivo_ingreso,
            mi.nombre AS motivo_ingreso_nombre,
            rv.fecha_hora_ingreso,
            rv.observacion,
            rv.con_vehiculo,
            rv.serie_placa,
            rv.numero_placa
        FROM
            recepcion_visita rv
        LEFT JOIN empleado emp_reg ON emp_reg.id = rv.id_empleado_registro
        LEFT JOIN empleado emp_cont ON emp_cont.id = rv.id_empleado_contacto
        LEFT JOIN motivo_ingreso mi ON mi.id = rv.id_motivo_ingreso
        WHERE rv.id = :id
        LIMIT 1;
        ';

        $item = DB::selectOne($sql, ['id' => $id]);

        if (! $item) {
            return null;
        }

        // Cargar visitantes de esta recepción
        $detalles = DB::select("
            SELECT
                rvd.id AS id_detalle,
                rvd.id_visitante,
                v.nombre AS visitante_nombre,
                v.apellido AS visitante_apellido,
                v.dni AS visitante_dni,
                v.telefono AS visitante_telefono,
                rvd.url_foto_documento,
                rvd.fecha_hora_salida,
                rvd.observacion_salida,
                CASE rvd.estado WHEN 2 THEN 'Fuera de Planta' ELSE 'En Planta' END AS estado
            FROM
                recepcion_visita_detalle rvd
            INNER JOIN visitante v ON v.id = rvd.id_visitante
            WHERE
                rvd.id_recepcion_visita = :id
        ", ['id' => $id]);

        $item->visitantes = array_map(function ($det) {
            $v = (array) $det;
            $val = $v['url_foto_documento'] ?? null;
            if (! empty($val)) {
                $decoded = json_decode($val, true);
                if (is_array($decoded)) {
                    $v['url_foto_documento'] = $decoded;
                } else {
                    $v['url_foto_documento'] = [$val];
                }
            } else {
                $v['url_foto_documento'] = [];
            }

            return $v;
        }, $detalles);

        return (array) $item;
    }

    /**
     * Crear un nuevo registro de recepción de visita.
     */
    public static function crear_recepcion(array $data): int
    {
        $recepcion = RecepcionVisita::create([
            'id_empleado_registro' => $data['id_empleado_registro'],
            'id_empleado_contacto' => $data['id_empleado_contacto'],
            'id_motivo_ingreso' => $data['id_motivo_ingreso'],
            'observacion' => $data['observacion'] ?? null,
            'con_vehiculo' => (bool) $data['con_vehiculo'],
            'serie_placa' => $data['serie_placa'] ?? null,
            'numero_placa' => $data['numero_placa'] ?? null,
            'fecha_hora_ingreso' => now()->toDateTimeString(),
        ]);

        return $recepcion->id;
    }
}
