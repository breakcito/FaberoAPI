<?php
namespace App\Services;

use App\Data\EmpleadosData;
use App\Shared\Enums\_Generic\EstadoBase;
use App\Shared\Responses\ApiResponse;

class EmpleadosService
{
    /**
     * Listar almacenes.
     */
    public static function get_empleados(
        ?int $id_empleado = null,
        ?EstadoBase $estado = EstadoBase::Activo,
    ) {
        $empleados = EmpleadosData::get_empleados(
            id_empleado: $id_empleado,
            estado: $estado
        );

        return ApiResponse::success($empleados);
    }
}