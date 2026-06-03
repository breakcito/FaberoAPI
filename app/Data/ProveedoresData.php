<?php

namespace App\Data;

use App\Shared\Enums\_Generic\EstadoBase;
use App\Shared\Enums\_Generic\TipoEntidad;
use Illuminate\Support\Facades\DB;

class ProveedoresData
{

    /**
     * Listado proveedores
     */
    public static function get_proveedores(
        ?int $id_proveedor = null,
        ?EstadoBase $estado = null,
        ?TipoEntidad $tipoEntidad = null
    ) {
        $sql = '
        SELECT 
            p.id AS id_proveedor,
            p.razon_social,
            p.direccion,
            IFNULL(p.ruc, p.dni) AS documento
        FROM proveedor p
        WHERE 1 = 1
        ';

        $params = [];

        if ($id_proveedor !== null) {
            $sql .= 'AND p.id = :id_proveedor';
            $params['id_proveedor'] = $id_proveedor;
            return DB::selectOne($sql, $params);
        }

        if ($estado !== null) {
            $sql .= 'AND p.estado = :estado';
            $params['estado'] = $estado->value;
        }

        if ($tipoEntidad !== null) {
            $sql .= 'AND p.tipo_entidad = :tipoEntidad';
            $params['tipoEntidad'] = $tipoEntidad->value;
        }

        $sql .= ' ORDER BY p.razon_social ASC';

        return DB::select($sql, $params);
    }
}
