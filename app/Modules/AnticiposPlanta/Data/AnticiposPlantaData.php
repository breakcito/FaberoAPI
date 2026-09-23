<?php

namespace App\Modules\AnticiposPlanta\Data;

use Illuminate\Support\Facades\DB;

class AnticiposPlantaData
{
    /**
     * Obtener listado de anticipos de planta con filtros opcionales.
     *
     * @param  array{id_planta?: int|null, estado?: string|null, fecha_inicio?: string|null, fecha_fin?: string|null}  $filters
     */
    public static function get_anticipos(array $filters = []): array
    {
        $sql = '
        SELECT
            ap.id,
            ap.id_planta,
            pd.razon_social AS planta_razon_social,
            pd.ruc AS planta_ruc,
            ap.id_empleado_registro,
            CONCAT(emp.nombre, " ", emp.apellido) AS empleado_registro_nombre,
            ap.codigo_comprobante,
            ap.saldo_inicial,
            ap.saldo_actual,
            ap.evidencias,
            ap.log_cambios,
            ap.estado,
            ap.created_at
        FROM
            anticipo_planta ap
        INNER JOIN planta_destino pd ON pd.id = ap.id_planta
        INNER JOIN empleado emp ON emp.id = ap.id_empleado_registro
        WHERE 1 = 1
        ';

        $params = [];

        if (! empty($filters['id_planta'])) {
            $sql .= ' AND ap.id_planta = :id_planta';
            $params['id_planta'] = (int) $filters['id_planta'];
        }

        if (! empty($filters['estado']) && $filters['estado'] !== 'Todos') {
            $sql .= ' AND ap.estado = :estado';
            $params['estado'] = $filters['estado'];
        }

        if (! empty($filters['fecha_inicio'])) {
            $sql .= ' AND ap.created_at >= :fecha_inicio';
            $params['fecha_inicio'] = $filters['fecha_inicio'].' 00:00:00';
        }

        if (! empty($filters['fecha_fin'])) {
            $sql .= ' AND ap.created_at <= :fecha_fin';
            $params['fecha_fin'] = $filters['fecha_fin'].' 23:59:59';
        }

        $sql .= ' ORDER BY ap.id DESC;';

        $results = DB::select($sql, $params);

        foreach ($results as $item) {
            $item->id = (int) $item->id;
            $item->id_planta = (int) $item->id_planta;
            $item->id_empleado_registro = (int) $item->id_empleado_registro;
            $item->saldo_inicial = (float) $item->saldo_inicial;
            $item->saldo_actual = (float) $item->saldo_actual;
            $item->evidencias = isset($item->evidencias) ? json_decode($item->evidencias, true) ?? [] : [];
            $item->log_cambios = isset($item->log_cambios) ? json_decode($item->log_cambios, true) ?? [] : [];
        }

        return $results;
    }

    /**
     * Obtener un anticipo de planta específico por su ID.
     */
    public static function get_anticipo_by_id(int $id): ?object
    {
        $sql = '
        SELECT
            ap.id,
            ap.id_planta,
            pd.razon_social AS planta_razon_social,
            pd.ruc AS planta_ruc,
            ap.id_empleado_registro,
            CONCAT(emp.nombre, " ", emp.apellido) AS empleado_registro_nombre,
            ap.codigo_comprobante,
            ap.saldo_inicial,
            ap.saldo_actual,
            ap.evidencias,
            ap.log_cambios,
            ap.estado,
            ap.created_at
        FROM
            anticipo_planta ap
        INNER JOIN planta_destino pd ON pd.id = ap.id_planta
        INNER JOIN empleado emp ON emp.id = ap.id_empleado_registro
        WHERE ap.id = :id
        LIMIT 1;
        ';

        $item = DB::selectOne($sql, ['id' => $id]);

        if (! $item) {
            return null;
        }

        $item->id = (int) $item->id;
        $item->id_planta = (int) $item->id_planta;
        $item->id_empleado_registro = (int) $item->id_empleado_registro;
        $item->saldo_inicial = (float) $item->saldo_inicial;
        $item->saldo_actual = (float) $item->saldo_actual;
        $item->evidencias = isset($item->evidencias) ? json_decode($item->evidencias, true) ?? [] : [];
        $item->log_cambios = isset($item->log_cambios) ? json_decode($item->log_cambios, true) ?? [] : [];

        return $item;
    }
}
