<?php

namespace App\Services;

use App\Data\ValorizacionVentaAuxData;
use App\Shared\Responses\ApiResponse;

class ValorizacionVentaAuxService
{
    /**
     * Plantas destino activas con distribuciones_detalle pendientes de valorizar
     */
    public static function get_plantas_con_distribuciones(): array
    {
        $data = ValorizacionVentaAuxData::get_plantas_con_distribuciones();

        return ApiResponse::success($data, 'Plantas con distribuciones pendientes obtenidas correctamente.');
    }

    /**
     * distribuciones_detalle disponibles para valorizar de una planta específica
     */
    public static function get_distribuciones_detalles_disponibles(int $idPlanta, ?int $idValorizacionEdicion = null): array
    {
        $data = ValorizacionVentaAuxData::get_distribuciones_detalles_disponibles($idPlanta, $idValorizacionEdicion);

        return ApiResponse::success($data, 'Distribuciones detalle disponibles obtenidas correctamente.');
    }

    /**
     * Condiciones comerciales activas por planta destino, indexadas por elemento químico
     */
    public static function get_condiciones_comerciales_planta(int $idPlanta): array
    {
        $data = ValorizacionVentaAuxData::get_condiciones_comerciales_planta($idPlanta);

        return ApiResponse::success($data, 'Condiciones comerciales de planta obtenidas correctamente.');
    }
}
