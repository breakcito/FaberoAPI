<?php

namespace App\Modules\Proveedores\Data;

use App\Models\Proveedor;
use Illuminate\Support\Facades\DB;

class ProveedoresData
{
    public static function get_proveedores(?int $id_proveedor = null)
    {
        $sql = '
        SELECT
            pr.id AS id_proveedor,
            pr.tipo_entidad,
            pr.dni,
            pr.ruc,
            pr.razon_social,
            pr.direccion,
            pr.telefono,
            pr.correo,
            pr.estado,
            (
                SELECT
                    COUNT(*)
                FROM
                    cuenta_bancaria_proveedor cn
                WHERE
                    cn.id_proveedor = pr.id AND 
                    cn.estado = "Activo"
            ) as cantidad_cuentas_bancarias
        FROM
            proveedor pr
        WHERE 1 = 1
        ';

        $params = [];
        if ($id_proveedor) {
            $sql .= ' AND pr.id = :id_proveedor';
            $params['id_proveedor'] = $id_proveedor;
            return DB::selectOne($sql, $params);
        }

        $sql .= ' ORDER BY pr.razon_social ASC;';
        return DB::select($sql, $params);
    }

    public static function get_proveedor_by_id(int $id_proveedor)
    {
        return self::get_proveedores(id_proveedor: $id_proveedor);
    }

    public static function crear_proveedor(
        string $tipoEntidad,
        ?string $dni,
        ?string $ruc,
        string $razonSocial,
        ?string $direccion,
        ?string $telefono,
        ?string $correo
    ): int {
        return Proveedor::insertGetId([
            'tipo_entidad' => $tipoEntidad,
            'dni' => $dni,
            'ruc' => $ruc,
            'razon_social' => $razonSocial,
            'direccion' => $direccion,
            'telefono' => $telefono,
            'correo' => $correo,
            'estado' => 'Activo'
        ]);
    }
}
