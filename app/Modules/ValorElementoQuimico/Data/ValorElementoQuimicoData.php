<?php

namespace App\Modules\ValorElementoQuimico\Data;

use App\Models\ValorElementoQuimico;
use Illuminate\Support\Facades\DB;

class ValorElementoQuimicoData
{
    /**
     * Buscar precio INTER exacto por elemento + fecha
     */
    public static function find_by_elemento_y_fecha(string $elemento, string $fecha): ?array
    {
        $row = DB::selectOne(
            'SELECT id, id_empleado_registro, elemento_quimico, inter, fecha, created_at
             FROM valor_elemento_quimico
             WHERE elemento_quimico = :elemento AND fecha = :fecha
             ORDER BY id DESC
             LIMIT 1',
            ['elemento' => $elemento, 'fecha' => $fecha],
        );

        if (! $row) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'id_empleado_registro' => $row->id_empleado_registro !== null ? (int) $row->id_empleado_registro : null,
            'elemento_quimico' => $row->elemento_quimico,
            'inter' => (float) $row->inter,
            'fecha' => $row->fecha,
            'created_at' => $row->created_at,
        ];
    }

    /**
     * Buscar el último precio INTER registrado para un elemento (por fecha DESC, id DESC).
     */
    public static function find_ultimo_por_elemento(string $elemento): ?array
    {
        $row = DB::selectOne(
            'SELECT v.id, v.id_empleado_registro, CONCAT(emp.nombre, " ", emp.apellido) AS empleado_registro_nombre,
                    v.elemento_quimico, v.inter, v.fecha, v.created_at
             FROM valor_elemento_quimico v
             LEFT JOIN empleado emp ON emp.id = v.id_empleado_registro
             WHERE v.elemento_quimico = :elemento
             ORDER BY v.fecha DESC, v.id DESC
             LIMIT 1',
            ['elemento' => $elemento],
        );

        if (! $row) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'id_empleado_registro' => $row->id_empleado_registro !== null ? (int) $row->id_empleado_registro : null,
            'empleado_registro_nombre' => $row->empleado_registro_nombre,
            'elemento_quimico' => $row->elemento_quimico,
            'inter' => (float) $row->inter,
            'fecha' => $row->fecha,
            'created_at' => $row->created_at,
        ];
    }

    /**
     * Buscar el precio INTER registrado en una fecha específica para un elemento (último ID de esa fecha).
     */
    public static function find_precio_en_fecha(string $elemento, string $fecha): ?array
    {
        $row = DB::selectOne(
            'SELECT v.id, v.id_empleado_registro, CONCAT(emp.nombre, " ", emp.apellido) AS empleado_registro_nombre,
                    v.elemento_quimico, v.inter, v.fecha, v.created_at
             FROM valor_elemento_quimico v
             LEFT JOIN empleado emp ON emp.id = v.id_empleado_registro
             WHERE v.elemento_quimico = :elemento AND v.fecha = :fecha
             ORDER BY v.id DESC
             LIMIT 1',
            ['elemento' => $elemento, 'fecha' => $fecha],
        );

        if (! $row) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'id_empleado_registro' => $row->id_empleado_registro !== null ? (int) $row->id_empleado_registro : null,
            'empleado_registro_nombre' => $row->empleado_registro_nombre,
            'elemento_quimico' => $row->elemento_quimico,
            'inter' => (float) $row->inter,
            'fecha' => $row->fecha,
            'created_at' => $row->created_at,
        ];
    }

    /**
     * Obtener los precios INTER vigentes de Oro y Plata para la fecha actual (o consultada).
     * Devuelve null en Oro/Plata si no se ha registrado cotización hoy.
     */
    public static function get_precios_vigentes(?string $fecha = null): array
    {
        $fechaActual = $fecha ?: date('Y-m-d');

        $oroHoy = self::find_precio_en_fecha('Oro', $fechaActual);
        $plataHoy = self::find_precio_en_fecha('Plata', $fechaActual);

        $oroUltimo = self::find_ultimo_por_elemento('Oro');
        $plataUltimo = self::find_ultimo_por_elemento('Plata');

        return [
            'fecha_consultada' => $fechaActual,
            'registrado_hoy' => ($oroHoy !== null || $plataHoy !== null),
            'Oro' => $oroHoy,
            'Plata' => $plataHoy,
            'ultimo_oro' => $oroUltimo,
            'ultimo_plata' => $plataUltimo,
        ];
    }

    /**
     * Listar historial de registros recientes de precios INTER.
     */
    public static function get_historial(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $rows = DB::select(
            'SELECT v.id, v.id_empleado_registro, CONCAT(emp.nombre, " ", emp.apellido) AS empleado_registro_nombre,
                    v.elemento_quimico, v.inter, v.fecha, v.created_at
             FROM valor_elemento_quimico v
             LEFT JOIN empleado emp ON emp.id = v.id_empleado_registro
             ORDER BY v.fecha DESC, v.id DESC
             LIMIT ' . (int) $limit
        );

        return array_map(function ($row) {
            return [
                'id' => (int) $row->id,
                'id_empleado_registro' => $row->id_empleado_registro !== null ? (int) $row->id_empleado_registro : null,
                'empleado_registro_nombre' => $row->empleado_registro_nombre,
                'elemento_quimico' => $row->elemento_quimico,
                'inter' => (float) $row->inter,
                'fecha' => $row->fecha,
                'created_at' => $row->created_at,
            ];
        }, $rows);
    }

    /**
     * Insertar un nuevo precio INTER
     */
    public static function crear_precio(
        int $idEmpleadoRegistro,
        string $elementoQuimico,
        float $inter,
        string $fecha,
    ): int {
        return ValorElementoQuimico::insertGetId([
            'id_empleado_registro' => $idEmpleadoRegistro,
            'elemento_quimico' => $elementoQuimico,
            'inter' => $inter,
            'fecha' => $fecha,
            'created_at' => now(),
        ]);
    }
}
