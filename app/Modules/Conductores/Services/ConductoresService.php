<?php

namespace App\Modules\Conductores\Services;

use App\Modules\Conductores\Data\ConductoresData;
use App\Shared\Responses\ApiResponse;

class ConductoresService
{
    public static function get_conductores(): array
    {
        $data = ConductoresData::get_conductores();

        return ApiResponse::success($data, 'Conductores obtenidos correctamente');
    }

    public static function crear_conductor(
        string $dni,
        ?string $ruc,
        string $nombre,
        string $apellido,
        string $numeroLicencia
    ): array {
        $id = ConductoresData::crear_conductor($dni, $ruc, $nombre, $apellido, $numeroLicencia);
        $nuevoConductor = ConductoresData::get_conductor_by_id($id);

        return ApiResponse::success($nuevoConductor, 'Conductor creado correctamente');
    }

    public static function editar_conductor(
        int $id,
        string $dni,
        ?string $ruc,
        string $nombre,
        string $apellido,
        string $numeroLicencia
    ): array {
        ConductoresData::editar_conductor($id, $dni, $ruc, $nombre, $apellido, $numeroLicencia);
        $updated = ConductoresData::get_conductor_by_id($id);

        return ApiResponse::success($updated, 'Conductor editado correctamente');
    }

    public static function cambiar_estado_conductor(int $id, string $estado): array
    {
        ConductoresData::cambiar_estado_conductor($id, $estado);
        $updated = ConductoresData::get_conductor_by_id($id);

        return ApiResponse::success($updated, 'Estado del conductor cambiado correctamente');
    }
}
