<?php

namespace App\Services;

use App\Data\ConductoresData;
use App\Shared\Responses\ApiResponse;

class ConductoresService
{
    public static function get_conductores(?int $id_conductor = null): array
    {
        $data = ConductoresData::get_conductores(id_conductor: $id_conductor);
        return ApiResponse::success($data, 'Conductores obtenidos correctamente');
    }

    public static function crear_conductor(
        string $dni,
        string $nombre,
        string $apellido,
        string $numeroLicencia,
        ?bool $return_object = false
    ): array {
        // Validamos si ya existe por dni, nombre + apellido, numero de licencia o ruc
        $ya_existe = ConductoresData::ya_existe($dni, $nombre, $apellido, $numeroLicencia);
        if ($ya_existe) {
            return ApiResponse::error('El conductor ya existe');
        }

        // si no existe, lo creamos
        $id = ConductoresData::crear_conductor($dni, $nombre, $apellido, $numeroLicencia);

        // si se debe devolver el objeto creado
        if ($return_object) {
            $nuevoConductor = ConductoresData::get_conductores(id_conductor: $id);
            return ApiResponse::success($nuevoConductor, 'Conductor creado correctamente');
        }

        return ApiResponse::success($id, 'Conductor creado correctamente');
    }
}
