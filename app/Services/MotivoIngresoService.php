<?php

namespace App\Services;

use App\Data\MotivoIngresoData;
use App\Shared\Responses\ApiResponse;

class MotivoIngresoService
{
    public static function get_motivos_ingreso(?bool $esRecepcionUnidad = null): array
    {
        $data = MotivoIngresoData::get_motivos_ingreso($esRecepcionUnidad);

        return ApiResponse::success($data, 'Motivos de ingreso obtenidos correctamente');
    }
}
