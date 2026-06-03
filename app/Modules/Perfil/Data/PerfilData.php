<?php

namespace App\Modules\Perfil\Data;

use Illuminate\Support\Facades\DB;

class PerfilData
{
    /**
     * Obtener toda la información necesaria para el perfil del usuario logueado
     */
    public static function get_info_perfil(int $id_usuario)
    {
        $sql = '
        SELECT
            usu.id as id_usuario,
            usu.username,
            emp.nombre,
            emp.apellido,
            emp.dni,
            emp.ruc,
            emp.carnet_extranjeria,
            emp.pasaporte,
            emp.fecha_nacimiento,
            emp.path_foto,
            rol.nombre as nombre_rol,
            car.nombre as nombre_cargo,
            are.nombre as nombre_area,
            em.razon_social as empresa_nombre
        FROM usuario usu
        INNER JOIN empleado emp ON emp.id = usu.id_empleado
        INNER JOIN rol ON rol.id = usu.id_rol
        LEFT JOIN cargo car ON car.id = emp.id_cargo
        LEFT JOIN area are ON are.id = car.id_area
        LEFT JOIN empresa em ON em.id = emp.id_empresa
        WHERE usu.id = :id_usuario
        ';

        return DB::selectOne($sql, ['id_usuario' => $id_usuario]);
    }
}
