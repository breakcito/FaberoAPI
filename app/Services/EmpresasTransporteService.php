<?php

namespace App\Services;

use App\Data\EmpresasTransporteData;
use App\Shared\Responses\ApiResponse;

class EmpresasTransporteService
{
    /**
     * Obtener listado global de empresas de transporte (datos simplificados)
     */
    public static function get_empresas_transporte(?int $id = null): array
    {
        $data = EmpresasTransporteData::get_empresas_transporte($id);

        return ApiResponse::success($data);
    }
}
