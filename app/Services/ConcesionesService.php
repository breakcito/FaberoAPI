<?php

namespace App\Services;

use App\Data\ConcesionesData;
use App\Shared\Responses\ApiResponse;

class ConcesionesService
{
    public static function get_concesiones(): array
    {
        $data = ConcesionesData::get_concesiones();

        return ApiResponse::success($data, 'Concesiones obtenidas correctamente');
    }

    public static function crear_concesion(
        int $idDepartamento,
        int $idProvincia,
        int $idDistrito,
        string $nombre,
        ?string $codigoReinfo
    ): array {
        $id = ConcesionesData::crear_concesion(
            $idDepartamento,
            $idProvincia,
            $idDistrito,
            $nombre,
            $codigoReinfo
        );

        $nuevaConcesion = ConcesionesData::get_concesion_by_id($id);

        return ApiResponse::success($nuevaConcesion, 'Concesión creada correctamente');
    }

    public static function editar_concesion(
        int $id,
        int $idDepartamento,
        int $idProvincia,
        int $idDistrito,
        string $nombre,
        ?string $codigoReinfo
    ): array {
        ConcesionesData::editar_concesion(
            $id,
            $idDepartamento,
            $idProvincia,
            $idDistrito,
            $nombre,
            $codigoReinfo
        );

        $updated = ConcesionesData::get_concesion_by_id($id);

        return ApiResponse::success($updated, 'Concesión editada correctamente');
    }

    public static function cambiar_estado_concesion(int $id, string $estado): array
    {
        ConcesionesData::cambiar_estado_concesion($id, $estado);
        $updated = ConcesionesData::get_concesion_by_id($id);

        return ApiResponse::success($updated, 'Estado de la concesión cambiado correctamente');
    }
}
