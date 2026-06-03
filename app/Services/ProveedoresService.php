<?php
namespace App\Services;

use App\Data\ProveedoresData;
use App\Shared\Enums\_Generic\EstadoBase;
use App\Shared\Enums\_Generic\TipoEntidad;
use App\Shared\Responses\ApiResponse;

class ProveedoresService
{
    /**
     * Listar almacenes.
     */
    public static function get_proveedores(
        ?int $id_proveedor = null,
        ?EstadoBase $estado = null,
        ?TipoEntidad $tipoEntidad = null
    ) {
        $empleados = ProveedoresData::get_proveedores(
            id_proveedor: $id_proveedor,
            estado: $estado,
            tipoEntidad: $tipoEntidad
        );

        return ApiResponse::success($empleados);
    }
}