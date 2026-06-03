<?php

namespace App\Modules\Roles;

use App\Shared\Responses\ApiResponse;
use App\Modules\Roles\Data\RolesData;
use App\Modules\Roles\Data\PermisosData;
use Illuminate\Support\Facades\DB;

class RolesService
{
    /**
     * Obtener listado de roles
     */
    public static function get_roles()
    {
        $roles = RolesData::get_roles();
        return ApiResponse::success($roles);
    }

    /**
     * Obtener la estructura completa para el selector de permisos
     */
    public static function get_estructura_permisos()
    {
        $estructura = PermisosData::get_estructura_permisos();
        return ApiResponse::success($estructura);
    }

    /**
     * Crear un rol y asignar sus modulos
     */
    public static function crear_rol(array $data)
    {
        try {
            return DB::transaction(function () use ($data) {

                // 1. Crear el objeto rol
                $id_rol = RolesData::crear_rol([
                    'nombre' => $data['nombre'],
                    'descripcion' => $data['descripcion'] ?? null,
                    'estado' => 'Activo'
                ]);

                // 2. Asignar modulos
                foreach ($data['modulos'] as $id_modulo) {
                    PermisosData::asignar_modulo_a_rol($id_rol, $id_modulo);
                }

                $nuevoRol = RolesData::get_rol_by_id($id_rol);
                return ApiResponse::success($nuevoRol, 'Rol creado correctamente con sus permisos.');
            });
        } catch (\Exception $e) {
            return ApiResponse::error('Ocurrió un error al registrar el rol: ' . $e->getMessage());
        }
    }

    /**
     * Obtener los IDs de los modulos asignados a un rol
     */
    public static function get_permisos_rol(int $id_rol)
    {
        $modulos = PermisosData::get_ids_modulos_por_rol($id_rol);
        return ApiResponse::success($modulos);
    }

    /**
     * Actualizar los permisos de un rol (solo diferencias)
     */
    public static function actualizar_permisos_rol(int $id_rol, array $modulos_nuevos)
    {
        try {
            return DB::transaction(function () use ($id_rol, $modulos_nuevos) {
                // 1. Obtener permisos actuales
                $actuales = PermisosData::get_ids_modulos_por_rol($id_rol);

                // 2. Calcular diferencias
                $agregar = array_diff($modulos_nuevos, $actuales);
                $eliminar = array_diff($actuales, $modulos_nuevos);

                // 3. Agregar solo los nuevos
                foreach ($agregar as $id_modulo) {
                    PermisosData::asignar_modulo_a_rol($id_rol, $id_modulo);
                }

                // 4. Eliminar solo los revocados
                foreach ($eliminar as $id_modulo) {
                    PermisosData::eliminar_modulo_de_rol($id_rol, $id_modulo);
                }

                return ApiResponse::success(null, 'Permisos actualizados correctamente.');
            });
        } catch (\Exception $e) {
            return ApiResponse::error('Error al actualizar los permisos: ' . $e->getMessage());
        }
    }
}
