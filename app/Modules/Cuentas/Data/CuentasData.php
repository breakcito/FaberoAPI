<?php

namespace App\Modules\Cuentas\Data;

use App\Models\Rol;
use Illuminate\Support\Facades\DB;

class CuentasData
{
    /**
     * Listar todas las cuentas de usuario con información de empleado y rol
     */
    public static function get_cuentas(): array
    {
        $sql = "
            SELECT 
                u.id as id_usuario,
                u.username,
                u.estado,
                u.id_rol,
                u.id_empleado,
                r.nombre as nombre_rol,
                e.nombre as nombre_empleado,
                e.apellido as apellido_empleado,
                e.id_empresa as id_empresa_pertenece,
                e.path_foto,
                emp.razon_social as empresa_pertenece
            FROM usuario u
            INNER JOIN empleado e ON e.id = u.id_empleado
            LEFT JOIN empresa emp ON emp.id = e.id_empresa
            INNER JOIN rol r ON r.id = u.id_rol
            ORDER BY e.apellido ASC
        ";

        return DB::select($sql);
    }

    /**
     * Listar empleados que aún no tienen una cuenta de usuario creada
     */
    public static function get_empleados_sin_cuenta(): array
    {
        $sql = "
            SELECT 
                e.id as id_empleado,
                CONCAT(e.nombre, ' ', e.apellido) as nombre_completo,
                e.dni,
                e.path_foto,
                e.id_empresa as id_empresa_pertenece
            FROM empleado e
            LEFT JOIN usuario u ON u.id_empleado = e.id
            WHERE u.id IS NULL AND e.estado = 'Activo'
            ORDER BY e.apellido ASC
        ";

        return DB::select($sql);
    }

    /**
     * Obtener los roles activos del sistema
     */
    public static function get_roles_disponibles(): array
    {
        return Rol::where('estado', 'Activo')
            ->select('id', 'nombre')
            ->orderBy('nombre', 'ASC')
            ->get()
            ->toArray();
    }

    /**
     * Obtener un usuario por ID
     */
    public static function get_usuario_by_id(int $id_usuario)
    {
        $sql = "
            SELECT 
                u.id as id_usuario,
                u.username,
                u.estado,
                u.id_rol,
                u.id_empleado,
                r.nombre as nombre_rol,
                e.nombre as nombre_empleado,
                e.apellido as apellido_empleado,
                e.id_empresa as id_empresa_pertenece,
                e.path_foto,
                emp.razon_social as empresa_pertenece
            FROM usuario u
            INNER JOIN empleado e ON e.id = u.id_empleado
            LEFT JOIN empresa emp ON emp.id = e.id_empresa
            INNER JOIN rol r ON r.id = u.id_rol
            WHERE u.id = :id_usuario
        ";

        return DB::selectOne($sql, ['id_usuario' => $id_usuario]);
    }

    /**
     * Insertar un nuevo usuario
     */
    public static function insert_usuario(
        int $id_rol,
        int $id_empleado,
        string $username,
        string $password_hash,
        string $estado = 'Activo'
    ): int {
        return DB::table('usuario')->insertGetId([
            'id_rol' => $id_rol,
            'id_empleado' => $id_empleado,
            'username' => $username,
            'password' => $password_hash,
            'estado' => $estado,
        ]);
    }

    /**
     * Actualizar datos del usuario
     */
    public static function update_usuario(int $id_usuario, array $data): bool
    {
        return (bool) DB::table('usuario')
            ->where('id', $id_usuario)
            ->update($data);
    }
}
