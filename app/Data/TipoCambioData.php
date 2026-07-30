<?php

namespace App\Data;

use App\Models\TipoCambio;
use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Support\Facades\DB;

class TipoCambioData
{
    /**
     * Listar tipos de cambio con filtro opcional por fecha y estado.
     *
     * @return array<int,object>
     */
    public static function get_tipos_cambio(?string $fecha = null, ?EstadoBase $estado = null): array
    {
        $sql = '
        SELECT
            tc.id,
            tc.id_empleado_registro,
            CONCAT(emp.nombre, " ", emp.apellido) AS empleado_registro_nombre,
            tc.valor_compra,
            tc.valor_venta,
            tc.fecha,
            tc.created_at,
            tc.estado
        FROM tipo_cambio tc
        INNER JOIN empleado emp ON emp.id = tc.id_empleado_registro
        WHERE 1 = 1
        ';

        $params = [];

        if ($fecha !== null) {
            $sql .= ' AND tc.fecha = :fecha';
            $params['fecha'] = $fecha;
        }

        if ($estado !== null) {
            $sql .= ' AND tc.estado = :estado';
            $params['estado'] = $estado->value;
        }

        $sql .= ' ORDER BY tc.fecha DESC, tc.id DESC';

        return DB::select($sql, $params);
    }

    /**
     * Obtener un tipo de cambio por su ID.
     */
    public static function get_tipo_cambio_by_id(int $id): ?object
    {
        $sql = '
        SELECT
            tc.id,
            tc.id_empleado_registro,
            CONCAT(emp.nombre, " ", emp.apellido) AS empleado_registro_nombre,
            tc.valor_compra,
            tc.valor_venta,
            tc.fecha,
            tc.created_at,
            tc.estado
        FROM tipo_cambio tc
        INNER JOIN empleado emp ON emp.id = tc.id_empleado_registro
        WHERE tc.id = :id
        LIMIT 1
        ';

        return DB::selectOne($sql, ['id' => $id]);
    }

    /**
     * Obtener un tipo de cambio por la fecha exacta.
     */
    public static function get_tipo_cambio_por_fecha(string $fecha): ?object
    {
        $sql = '
        SELECT
            tc.id,
            tc.id_empleado_registro,
            CONCAT(emp.nombre, " ", emp.apellido) AS empleado_registro_nombre,
            tc.valor_compra,
            tc.valor_venta,
            tc.fecha,
            tc.created_at,
            tc.estado
        FROM tipo_cambio tc
        INNER JOIN empleado emp ON emp.id = tc.id_empleado_registro
        WHERE tc.fecha = :fecha
        ORDER BY tc.id DESC
        LIMIT 1
        ';

        return DB::selectOne($sql, ['fecha' => $fecha]);
    }

    /**
     * Verificar si ya existe un tipo de cambio activo registrado para la fecha dada.
     */
    public static function existe_tipo_cambio_en_fecha(string $fecha): bool
    {
        return TipoCambio::where('fecha', $fecha)
            ->where('estado', EstadoBase::Activo->value)
            ->exists();
    }

    /**
     * Crear un tipo de cambio y devolver el ID generado.
     */
    public static function crear_tipo_cambio(
        int $idEmpleadoRegistro,
        float $valorCompra,
        float $valorVenta,
        string $fecha
    ): int {
        return TipoCambio::insertGetId([
            'id_empleado_registro' => $idEmpleadoRegistro,
            'valor_compra' => round($valorCompra, 3),
            'valor_venta' => round($valorVenta, 3),
            'fecha' => $fecha,
            'created_at' => now(),
            'estado' => EstadoBase::Activo->value,
        ]);
    }

    /**
     * Cambiar estado (Activo/Inactivo) de un tipo de cambio.
     */
    public static function cambiar_estado_tipo_cambio(int $id, string $estado): bool
    {
        return TipoCambio::where('id', $id)->update(['estado' => $estado]) >= 0;
    }
}
