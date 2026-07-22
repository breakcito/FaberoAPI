<?php

namespace App\Modules\AnticiposProveedor\Data;

use Illuminate\Support\Facades\DB;

class AnticiposProveedorData
{
    /**
     * Obtener listado de anticipos de proveedor con filtros opcionales.
     *
     * @param  array{id_proveedor_minero?: int|null, estado?: string|null, fecha_inicio?: string|null, fecha_fin?: string|null}  $filters
     */
    public static function get_anticipos(array $filters = []): array
    {
        $sql = '
        SELECT
            ap.id,
            ap.id_proveedor_minero,
            p.razon_social AS proveedor_nombre,
            ap.id_empleado_registro,
            CONCAT(emp.nombre, " ", emp.apellido) AS empleado_registro_nombre,
            ap.serie_factura,
            ap.numero_factura,
            ap.saldo_inicial,
            ap.saldo_actual,
            ap.evidencias,
            ap.log_cambios,
            ap.estado,
            ap.created_at
        FROM
            anticipo_proveedor ap
        INNER JOIN proveedor p ON p.id = ap.id_proveedor_minero
        INNER JOIN empleado emp ON emp.id = ap.id_empleado_registro
        WHERE 1 = 1
        ';

        $params = [];

        if (! empty($filters['id_proveedor_minero'])) {
            $sql .= ' AND ap.id_proveedor_minero = :id_proveedor_minero';
            $params['id_proveedor_minero'] = (int) $filters['id_proveedor_minero'];
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
            $item->id_proveedor_minero = (int) $item->id_proveedor_minero;
            $item->id_empleado_registro = (int) $item->id_empleado_registro;
            $item->saldo_inicial = (float) $item->saldo_inicial;
            $item->saldo_actual = (float) $item->saldo_actual;
            $item->evidencias = isset($item->evidencias) ? json_decode($item->evidencias, true) ?? [] : [];
            $item->log_cambios = isset($item->log_cambios) ? json_decode($item->log_cambios, true) ?? [] : [];
        }

        return $results;
    }

    /**
     * Obtener un anticipo específico por su ID.
     */
    public static function get_anticipo_by_id(int $id): ?object
    {
        $sql = '
        SELECT
            ap.id,
            ap.id_proveedor_minero,
            p.razon_social AS proveedor_nombre,
            ap.id_empleado_registro,
            CONCAT(emp.nombre, " ", emp.apellido) AS empleado_registro_nombre,
            ap.serie_factura,
            ap.numero_factura,
            ap.saldo_inicial,
            ap.saldo_actual,
            ap.evidencias,
            ap.log_cambios,
            ap.estado,
            ap.created_at
        FROM
            anticipo_proveedor ap
        INNER JOIN proveedor p ON p.id = ap.id_proveedor_minero
        INNER JOIN empleado emp ON emp.id = ap.id_empleado_registro
        WHERE ap.id = :id
        LIMIT 1;
        ';

        $item = DB::selectOne($sql, ['id' => $id]);

        if (! $item) {
            return null;
        }

        $item->id = (int) $item->id;
        $item->id_proveedor_minero = (int) $item->id_proveedor_minero;
        $item->id_empleado_registro = (int) $item->id_empleado_registro;
        $item->saldo_inicial = (float) $item->saldo_inicial;
        $item->saldo_actual = (float) $item->saldo_actual;
        $item->evidencias = isset($item->evidencias) ? json_decode($item->evidencias, true) ?? [] : [];
        $item->log_cambios = isset($item->log_cambios) ? json_decode($item->log_cambios, true) ?? [] : [];

        return $item;
    }
}
