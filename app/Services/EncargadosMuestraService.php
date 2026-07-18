<?php

namespace App\Services;

use App\Data\EncargadosMuestraData;
use App\Shared\Responses\ApiResponse;

class EncargadosMuestraService
{
    /**
     * Obtener listado global simplificado de encargados de muestra
     */
    public static function get_encargados_muestra(): array
    {
        $data = EncargadosMuestraData::get_encargados_muestra();

        return ApiResponse::success($data, 'Encargados de muestra obtenidos correctamente');
    }
}
