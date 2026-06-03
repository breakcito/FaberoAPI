<?php
namespace App\Services;

use App\Data\EmpresasData;
use App\Shared\Enums\_Generic\EstadoBase;
use App\Shared\Responses\ApiResponse;

class EmpresasService
{
    /**
     * Listar empresas.
     */
    public static function get_empresas(
        ?int $id_empresa = null,
        ?EstadoBase $estado = null
    ) {
        $empresas = EmpresasData::get_empresas(
            id_empresa: $id_empresa,
            estado: $estado,
        );

        return ApiResponse::success($empresas);
    }
}