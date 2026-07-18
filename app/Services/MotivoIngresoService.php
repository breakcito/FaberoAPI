<?php

namespace App\Services;

use App\Data\MotivoIngresoData;
use App\Shared\Responses\ApiResponse;

class MotivoIngresoService
{
    public static function get_motivos_ingreso(): array
    {
        $data = MotivoIngresoData::get_motivos_ingreso();

        return ApiResponse::success($data, 'Motivos de ingreso obtenidos correctamente');
    }
}
