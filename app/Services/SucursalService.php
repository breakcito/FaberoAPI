<?php

namespace App\Services;

use App\Data\SucursalesData;
use App\Shared\Enums\_Generic\EstadoBase;
use App\Shared\Responses\ApiResponse;

class SucursalService
{
    /**
     * Obtiene las sucursales para el select global
     */
    public static function get_sucursales(?EstadoBase $estado = EstadoBase::Activo, ?int $id_usuario = null)
    {
        return ApiResponse::success(SucursalesData::get_sucursales($estado, $id_usuario));
    }
}
