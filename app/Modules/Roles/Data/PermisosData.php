<?php

namespace App\Modules\Roles\Data;

use App\Models\Menu;
use App\Models\Submenu;
use App\Models\Modulo;
use App\Models\ModuloRol;

class PermisosData
{
    /**
     * Obtener la estructura jerarquica de Menú -> Submenú -> Módulo
     */
    public static function get_estructura_permisos()
    {
        // 1. Obtener menus activos
        $menus = Menu::where('estado', 'Activo')->get();

        foreach ($menus as $menu) {
            // 2. Obtener submenus de cada menu
            $submenus = Submenu::where('id_menu', $menu->id)
                ->where('estado', 'Activo')
                ->get();

            foreach ($submenus as $submenu) {
                // 3. Obtener modulos de cada submenu
                $submenu->modulos = Modulo::where('id_submenu', $submenu->id)
                    ->where('estado', 'Activo')
                    ->get();
            }

            $menu->submenus = $submenus;
        }

        return $menus;
    }

    /**
     * Asignar un modulo a un rol (tabla pivote)
     */
    public static function asignar_modulo_a_rol(int $id_rol, int $id_modulo): void
    {
        ModuloRol::create([
            'id_rol' => $id_rol,
            'id_modulo' => $id_modulo
        ]);
    }

    /**
     * Obtener solo los IDs de los modulos de un rol
     */
    public static function get_ids_modulos_por_rol(int $id_rol): array
    {
        return ModuloRol::where('id_rol', $id_rol)
            ->pluck('id_modulo')
            ->toArray();
    }

    /**
     * Eliminar todas las asociaciones de modulos de un rol
     */
    public static function limpiar_permisos_rol(int $id_rol): void
    {
        ModuloRol::where('id_rol', $id_rol)->delete();
    }

    /**
     * Eliminar un modulo específico de un rol
     */
    public static function eliminar_modulo_de_rol(int $id_rol, int $id_modulo): void
    {
        ModuloRol::where('id_rol', $id_rol)
            ->where('id_modulo', $id_modulo)
            ->delete();
    }
}
