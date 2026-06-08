<?php

namespace App\Services;

use App\Data\UbigeoData;
use App\Shared\Responses\ApiResponse;

class UbigeoService
{
    /**
     * Obtener departamentos
     */
    public static function get_departamentos(): array
    {
        $data = UbigeoData::get_departamentos();

        return ApiResponse::success($data);
    }

    /**
     * Obtener provincias por departamento
     */
    public static function get_provincias(int $id_departamento): array
    {
        $data = UbigeoData::get_provincias($id_departamento);

        return ApiResponse::success($data);
    }

    /**
     * Obtener distritos por provincia
     */
    public static function get_distritos(int $id_provincia): array
    {
        $data = UbigeoData::get_distritos($id_provincia);

        return ApiResponse::success($data);
    }
}
