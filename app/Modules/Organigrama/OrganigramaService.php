<?php

namespace App\Modules\Organigrama;

use App\Shared\Responses\ApiResponse;
use App\Modules\Organigrama\Data\AreasData;
use App\Modules\Organigrama\Data\CargosData;

class OrganigramaService
{
    /**
     * ÁREAS
     */

    public static function get_areas(): array|object
    {
        $areas = AreasData::get_areas();
        return ApiResponse::success($areas);
    }

    public static function crear_area(string $nombre): array|object
    {
        if (AreasData::verificar_nombre_duplicado($nombre)) {
            return ApiResponse::error('Ya existe un área con este nombre.');
        }

        $id = AreasData::crear_area($nombre);
        $nueva = AreasData::get_area_by_id($id);

        return ApiResponse::success($nueva, 'Área creada correctamente');
    }

    /**
     * CARGOS
     */

    public static function get_cargos(int $id_area): array|object
    {
        $cargos = CargosData::get_cargos(id_area: $id_area);
        return ApiResponse::success($cargos);
    }

    public static function crear_cargo(string $nombre, int $id_area): array|object
    {
        if (CargosData::verificar_nombre_duplicado($nombre, $id_area)) {
            return ApiResponse::error('Ya existe este cargo en la misma área.');
        }

        $id = CargosData::crear_cargo($nombre, $id_area);
        $nuevo = CargosData::get_cargo_by_id($id);

        return ApiResponse::success($nuevo, 'Cargo creado correctamente');
    }

    public static function cambiar_estado_cargo(int $id_cargo): array|object
    {
        $nuevo_estado = CargosData::cambiar_estado($id_cargo);

        return ApiResponse::success(null, "Cargo marcado como $nuevo_estado");
    }
}
