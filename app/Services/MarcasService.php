<?php
namespace App\Services;

use App\Data\MarcasData;
use App\Shared\Enums\_Generic\EstadoBase;
use App\Shared\Responses\ApiResponse;

class MarcasService
{
    /**
     * Obtiene las marcas
     */
    public static function get_marcas(
        ?int $id_marca = null,
        ?EstadoBase $estado = EstadoBase::Activo
    ) {
        return ApiResponse::success(MarcasData::get_marcas(id_marca: $id_marca, estado: $estado));
    }

    /**
     * Registrar una nueva marca
     */
    public static function crear_marca(
        ?string $nombre = null
    ) {
        if (!$nombre) {
            return ApiResponse::error('El nombre de la marca es obligatorio');
        }

        if (MarcasData::existe_marca($nombre)) {
            return ApiResponse::error('Ya existe una marca con ese nombre');
        }

        $id_marca = MarcasData::crear_marca(
            nombre: $nombre
        );

        return ApiResponse::success(MarcasData::get_marcas(id_marca: $id_marca));
    }
}