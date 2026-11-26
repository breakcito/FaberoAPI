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
            pr.origen_guias,
            pr.destino_guias,
            pr.estado,
            (
                SELECT
                    COUNT(*)
                FROM
                    cuenta_bancaria_proveedor cn
                WHERE
                    cn.id_proveedor = pr.id AND 
                    cn.estado = "Activo"
            ) as cantidad_cuentas_bancarias,
            (
                SELECT
                    COUNT(*)
                FROM
                    contacto_proveedor ct
                WHERE
                    ct.id_proveedor = pr.id
            ) as cantidad_contactos
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
        ?string $correo,
        ?string $origenGuias = null,
        ?string $destinoGuias = null
    ): int {
        return Proveedor::insertGetId([
            'tipo_entidad' => $tipoEntidad,
            'dni' => $dni,
            'ruc' => $ruc,
            'razon_social' => $razonSocial,
            'direccion' => $direccion,
            'telefono' => $telefono,
            'correo' => $correo,
            'origen_guias' => $origenGuias,
            'destino_guias' => $destinoGuias,
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
        ?string $correo,
        ?string $origenGuias = null,
        ?string $destinoGuias = null
    ): bool {
        return Proveedor::where('id', $id)->update([
            'tipo_entidad' => $tipoEntidad,
            'dni' => $dni,
            'ruc' => $ruc,
            'razon_social' => $razonSocial,
            'direccion' => $direccion,
            'telefono' => $telefono,
            'correo' => $correo,
            'origen_guias' => $origenGuias,
            'destino_guias' => $destinoGuias,
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

    /**
     * Contactos registrados de un proveedor.
     */
    public static function get_contactos(int $id_proveedor): array
    {
        return DB::select('
            SELECT
                ct.id,
                ct.id_proveedor,
                ct.id_empleado_registro,
                ct.nombre_completo,
                ct.dni,
                ct.telefono,
                ct.correo,
                ct.created_at,
                CONCAT(emp.nombre, " ", emp.apellido) AS empleado_registro_nombre
            FROM
                contacto_proveedor ct
            LEFT JOIN empleado emp ON emp.id = ct.id_empleado_registro
            WHERE
                ct.id_proveedor = :id_proveedor
            ORDER BY ct.id ASC
        ', ['id_proveedor' => $id_proveedor]);
    }

    public static function get_contacto_por_id(int $id_contacto)
    {
        return DB::table('contacto_proveedor')
            ->where('id', $id_contacto)
            ->first();
    }

    public static function crear_contacto(
        int $idProveedor,
        int $idEmpleadoRegistro,
        string $nombreCompleto,
        ?string $dni,
        ?string $telefono,
        ?string $correo
    ): int {
        return DB::table('contacto_proveedor')->insertGetId([
            'id_proveedor' => $idProveedor,
            'id_empleado_registro' => $idEmpleadoRegistro,
            'nombre_completo' => $nombreCompleto,
            'dni' => $dni,
            'telefono' => $telefono,
            'correo' => $correo,
        ]);
    }

    public static function editar_contacto(
        int $id_contacto,
        string $nombreCompleto,
        ?string $dni,
        ?string $telefono,
        ?string $correo
    ): bool {
        return DB::table('contacto_proveedor')
            ->where('id', $id_contacto)
            ->update([
                'nombre_completo' => $nombreCompleto,
                'dni' => $dni,
                'telefono' => $telefono,
                'correo' => $correo,
            ]) >= 0;
    }

    public static function eliminar_contacto(int $id_contacto): bool
    {
        return DB::table('contacto_proveedor')
            ->where('id', $id_contacto)
            ->delete() > 0;
    }

    /**
     * Contacto que debe figurar en los documentos del proveedor.
     *
     * Si el proveedor tiene contactos registrados se usa el primero. Si no
     * tiene ninguno, es natural que el propio proveedor sea el contacto, así
     * que se replican sus datos (razón social, teléfono y correo).
     *
     * Cuando el contacto existe pero le falta el teléfono o el correo, se
     * completa con los del proveedor.
     */
    public static function get_contacto_documento(int $id_proveedor): array
    {
        $proveedor = DB::table('proveedor')
            ->where('id', $id_proveedor)
            ->first();

        $contacto = DB::table('contacto_proveedor')
            ->where('id_proveedor', $id_proveedor)
            ->orderBy('id')
            ->first();

        $nombreProveedor = $proveedor->razon_social ?? null;
        $telefonoProveedor = $proveedor->telefono ?? null;
        $correoProveedor = $proveedor->correo ?? null;

        if (! $contacto) {
            return [
                'id_contacto' => null,
                'nombre' => $nombreProveedor !== null ? (string) $nombreProveedor : null,
                'telefono' => $telefonoProveedor !== null ? (string) $telefonoProveedor : null,
                'correo' => $correoProveedor !== null ? (string) $correoProveedor : null,
                'origen' => 'proveedor',
            ];
        }

        $telefono = $contacto->telefono !== null && $contacto->telefono !== ''
            ? (string) $contacto->telefono
            : ($telefonoProveedor !== null ? (string) $telefonoProveedor : null);

        $correo = $contacto->correo !== null && $contacto->correo !== ''
            ? (string) $contacto->correo
            : ($correoProveedor !== null ? (string) $correoProveedor : null);

        return [
            'id_contacto' => (int) $contacto->id,
            'nombre' => (string) $contacto->nombre_completo,
            'telefono' => $telefono,
            'correo' => $correo,
            'origen' => 'contacto',
        ];
    }
}
