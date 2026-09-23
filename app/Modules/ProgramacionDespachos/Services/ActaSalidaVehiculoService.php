<?php

namespace App\Modules\ProgramacionDespachos\Services;

use App\Modules\ProgramacionDespachos\Data\ActaSalidaVehiculoData;
use App\Shared\Responses\ApiResponse;

class ActaSalidaVehiculoService
{
    /**
     * Resuelve los datos del acta de salida de una distribución.
     *
     * @return array<string, mixed>
     */
    public static function get_por_distribucion(int $idDistribucion): array
    {
        $data = ActaSalidaVehiculoData::get_por_distribucion($idDistribucion);

        if ($data === null) {
            return ApiResponse::error('No se encontró la distribución indicada.', 404);
        }

        return ApiResponse::success($data, 'Acta de salida obtenida correctamente.');
    }
}
