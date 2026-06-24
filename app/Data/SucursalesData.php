<?php

namespace App\Data;

use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Support\Facades\DB;

class SucursalesData
{
    /**
     * Obtener listado de sucursales activas (id_sucursal y nombre)
     */
    public static function get_sucursales(?EstadoBase $estado = EstadoBase::Activo, ?int $id_usuario = null)
    {
        $sql = '
        SELECT
            sc.id AS id_sucursal,
            sc.nombre
        FROM
            sucursal sc
        WHERE 1=1
        ';

        $params = [];
        if ($estado != null) {
            $sql .= ' AND sc.estado = :estado';
            $params['estado'] = $estado->value;
        }

        if ($id_usuario !== null) {
            $sql .= ' AND sc.id IN (SELECT id_sucursal FROM sucursal_usuario WHERE id_usuario = :id_usuario)';
            $params['id_usuario'] = $id_usuario;
        }

        $sql .= ' ORDER BY sc.nombre ASC;';

        return DB::select($sql, $params);
    }
}
