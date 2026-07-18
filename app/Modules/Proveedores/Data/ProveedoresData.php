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
            'estado' => 'Activo',
        ]);
    }

    public static function editar_proveedor(
        int $id,
        string $tipoEntidad,
        ?string $dni,
        ?string $ruc,
        string $razonSocial,
        ?string $direccion,
        ?string $telefono,
        ?string $correo
    ): bool {
        return Proveedor::where('id', $id)->update([
            'tipo_entidad' => $tipoEntidad,
            'dni' => $dni,
            'ruc' => $ruc,
            'razon_social' => $razonSocial,
            'direccion' => $direccion,
            'telefono' => $telefono,
            'correo' => $correo,
        ]) >= 0;
    }

    public static function cambiar_estado_proveedor(int $id, string $estado): bool
    {
        return Proveedor::where('id', $id)->update(['estado' => $estado]) >= 0;
    }

    public static function eliminar_proveedor(int $id): bool
    {
        return Proveedor::where('id', $id)->delete() > 0;
    }

    public static function get_concesiones(int $id_proveedor): array
    {
        return DB::select('
            SELECT
                c.id AS id_concesion,
                c.id_departamento,
                dep.nombre AS departamento,
                c.id_provincia,
                prov.nombre AS provincia,
                c.id_distrito,
                dist.nombre AS distrito,
                c.nombre,
                c.codigo_reinfo,
                c.estado
            FROM
                concesion c
            INNER JOIN concesion_proveedor cp ON cp.id_concesion = c.id
            LEFT JOIN departamento dep ON dep.id = c.id_departamento
            LEFT JOIN provincia prov ON prov.id = c.id_provincia
            LEFT JOIN distrito dist ON dist.id = c.id_distrito
            WHERE
                cp.id_proveedor = :id_proveedor
            ORDER BY c.nombre ASC
        ', ['id_proveedor' => $id_proveedor]);
    }

    public static function existe_concesion_proveedor(int $id_proveedor, int $id_concesion): bool
    {
        return DB::table('concesion_proveedor')
            ->where('id_proveedor', $id_proveedor)
            ->where('id_concesion', $id_concesion)
            ->exists();
    }

    public static function asociar_concesion(int $id_proveedor, int $id_concesion): int
    {
        return DB::table('concesion_proveedor')->insertGetId([
            'id_proveedor' => $id_proveedor,
            'id_concesion' => $id_concesion,
        ]);
    }

    public static function desasociar_concesion(int $id_proveedor, int $id_concesion): bool
    {
        return DB::table('concesion_proveedor')
            ->where('id_proveedor', $id_proveedor)
            ->where('id_concesion', $id_concesion)
            ->delete() > 0;
    }
}
