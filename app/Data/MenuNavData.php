<?php

namespace App\Data;

use Illuminate\Support\Facades\DB;

class MenuNavData
{
    /**
     * Obtener los modulos del sistema para el menu de navegacion
     */
    public static function get_menus_by_rol(int $id_rol): array
    {
        $sql = '
        SELECT DISTINCT
            mn.id AS id_menu,
            mn.nombre,
            mn.path,
            mn.numero_orden
        FROM menu mn
        INNER JOIN submenu sb ON sb.id_menu = mn.id
        INNER JOIN modulo md ON md.id_submenu = sb.id
        INNER JOIN modulo_rol mr ON mr.id_modulo = md.id
        WHERE
            mr.id_rol = :id_rol AND
            mn.estado = "Activo"
        ORDER BY mn.numero_orden ASC;
        ';

        return DB::select($sql, ['id_rol' => $id_rol]);
    }

    /**
     * Obtener los submenus filtrados por múltiples IDs de menus
     */
    public static function get_submenus_by_rol_and_menus(int $id_rol, array $ids_menu): array
    {
        if (empty($ids_menu)) return [];
        $placeholders = implode(',', array_fill(0, count($ids_menu), '?'));

        $sql = "
        SELECT DISTINCT
            sb.id as id_submenu,
            sb.id_menu,
            sb.nombre,
            sb.path,
            sb.numero_orden
        FROM submenu sb
        INNER JOIN modulo md ON md.id_submenu = sb.id
        INNER JOIN modulo_rol mr ON mr.id_modulo = md.id
        WHERE
            mr.id_rol = ? AND 
            sb.id_menu IN ($placeholders) AND
            sb.estado = 'Activo' AND
            md.estado = 'Activo'
        ORDER BY sb.numero_orden ASC;
        ";

        return DB::select($sql, array_merge([$id_rol], $ids_menu));
    }

    /**
     * Obtener los modulos filtrados por múltiples IDs de submenus
     */
    public static function get_modulos_by_rol_and_submenus(int $id_rol, array $ids_submenu): array
    {
        if (empty($ids_submenu)) return [];
        $placeholders = implode(',', array_fill(0, count($ids_submenu), '?'));

        $sql = "
        SELECT DISTINCT
            md.id AS id_modulo,
            md.id_submenu,
            md.nombre,
            md.path,
            md.numero_orden
        FROM modulo md
        INNER JOIN modulo_rol mr ON mr.id_modulo = md.id
        WHERE
            mr.id_rol = ? AND
            md.id_submenu IN ($placeholders) AND
            md.estado = 'Activo'
        ORDER BY md.numero_orden ASC;
        ";

        return DB::select($sql, array_merge([$id_rol], $ids_submenu));
    }
}
