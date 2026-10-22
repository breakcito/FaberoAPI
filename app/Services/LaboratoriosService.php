<?php

namespace App\Services;

use App\Data\LaboratoriosData;
use App\Shared\Responses\ApiResponse;

class LaboratoriosService
{
    /**
     * Obtener laboratorios
     */
    public static function get_laboratorios(?int $id = null)
    {
        return ApiResponse::success(LaboratoriosData::get_laboratorios($id));
    }

    /**
     * Crear un nuevo laboratorio
     */
    public static function crear_laboratorio(
        int $id_empleado_registro,
        ?string $nombre = null,
        ?string $ruc = null
    ) {
        if (! $nombre || trim($nombre) === '') {
            return ApiResponse::error('El nombre del laboratorio es obligatorio');
        }

        $nombreLimpio = trim($nombre);

        if (LaboratoriosData::existe_laboratorio($nombreLimpio)) {
            return ApiResponse::error('Ya existe un laboratorio con ese nombre');
        }

        $id = LaboratoriosData::crear_laboratorio(
            id_empleado_registro: $id_empleado_registro,
            nombre: $nombreLimpio,
            ruc: $ruc ? trim($ruc) : null
        );

        $laboratorios = LaboratoriosData::get_laboratorios($id);

        return ApiResponse::success($laboratorios[0] ?? null, 'Laboratorio registrado correctamente');
    }
}
