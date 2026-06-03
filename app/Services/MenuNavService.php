<?php

namespace App\Services;

use App\Shared\Responses\ApiResponse;
use App\Data\MenuNavData;

class MenuNavService
{
    public static function get_menu_navegacion(int $idRol): array
    {
        // 1. Obtener todos los menus
        $menus = MenuNavData::get_menus_by_rol($idRol);
        if (empty($menus)) return ApiResponse::success([]);

        $idsMenus = array_column($menus, 'id_menu');

        // 2. Obtener TODOS los submenus de esos menus
        $todosLosSubmenus = MenuNavData::get_submenus_by_rol_and_menus($idRol, $idsMenus);
        $idsSubmenus = array_column($todosLosSubmenus, 'id_submenu');

        // 3. Obtener TODOS los modulos de esos submenus
        $todosLosModulos = !empty($idsSubmenus)
            ? MenuNavData::get_modulos_by_rol_and_submenus($idRol, $idsSubmenus)
            : [];

        // AGRUPACIÓN

        // Agrupar modulos por su padre (id_submenu)
        $modulosAgrupados = [];
        foreach ($todosLosModulos as $modulo) {
            $modulosAgrupados[$modulo->id_submenu][] = $modulo;
        }

        // Agrupar submenus por su padre (id_menu)
        $submenusAgrupados = [];
        foreach ($todosLosSubmenus as $submenu) {
            $submenusAgrupados[$submenu->id_menu][] = $submenu;
        }

        // CONSTRUCCIÓN DE LA ESTRUCTURA

        $estructura = [];
        foreach ($menus as $menu) {
            $submenusData = [];

            $misSubmenus = $submenusAgrupados[$menu->id_menu] ?? [];

            foreach ($misSubmenus as $submenu) {
                $misModulos = $modulosAgrupados[$submenu->id_submenu] ?? [];

                $submenusData[] = [
                    'id_submenu' => $submenu->id_submenu,
                    'nombre'     => $submenu->nombre,
                    'path'       => $submenu->path,
                    'modulos'    => array_map(function ($modulo) use ($menu, $submenu) {
                        return [
                            'id_modulo' => $modulo->id_modulo,
                            'nombre'    => $modulo->nombre,
                            'url'       => "/{$menu->path}/{$submenu->path}/{$modulo->path}",
                        ];
                    }, $misModulos),
                ];
            }

            $estructura[] = [
                'id_menu'  => $menu->id_menu,
                'nombre'   => $menu->nombre,
                'path'     => $menu->path,
                'submenus' => $submenusData,
            ];
        }

        return ApiResponse::success($estructura);
    }
}
