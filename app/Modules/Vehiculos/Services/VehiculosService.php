<?php

namespace App\Modules\Vehiculos\Services;

use App\Modules\Vehiculos\Data\VehiculosData;
use App\Shared\Responses\ApiResponse;

class VehiculosService
{
    public static function get_vehiculos(): array
    {
        $data = VehiculosData::get_vehiculos();

        return ApiResponse::success($data, 'Vehículos obtenidos correctamente');
    }

    public static function crear_vehiculo(
        int $idMarca,
        int $idEmpresaTransporte,
        int $idTipoVehiculo,
        ?string $seriePlaca,
        string $numeroPlaca,
        ?string $numeroConstanciaMtc,
        float $capacidad,
        float $tara,
        ?float $largo,
        ?float $ancho,
        ?float $alto
    ): array {
        $existenteId = VehiculosData::buscar_vehiculo_existente($seriePlaca, $numeroPlaca);
        if ($existenteId !== null) {
            $vehiculo = VehiculosData::get_vehiculo_by_id($existenteId);
            if (! empty($vehiculo)) {
                $vehiculo['ya_existia'] = true;
            }

            return ApiResponse::success($vehiculo, 'El vehículo ya se encontraba registrado.');
        }

        $id = VehiculosData::crear_vehiculo(
            $idMarca,
            $idEmpresaTransporte,
            $idTipoVehiculo,
            $seriePlaca,
            $numeroPlaca,
            $numeroConstanciaMtc,
            $capacidad,
            $tara,
            $largo,
            $ancho,
            $alto
        );
        $nuevoVehiculo = VehiculosData::get_vehiculo_by_id($id);
        if (! empty($nuevoVehiculo)) {
            $nuevoVehiculo['ya_existia'] = false;
        }

        return ApiResponse::success($nuevoVehiculo, 'Vehículo registrado correctamente');
    }

    public static function editar_vehiculo(
        int $id,
        int $idMarca,
        int $idEmpresaTransporte,
        int $idTipoVehiculo,
        ?string $seriePlaca,
        string $numeroPlaca,
        ?string $numeroConstanciaMtc,
        float $capacidad,
        float $tara,
        ?float $largo,
        ?float $ancho,
        ?float $alto
    ): array {
        VehiculosData::editar_vehiculo(
            $id,
            $idMarca,
            $idEmpresaTransporte,
            $idTipoVehiculo,
            $seriePlaca,
            $numeroPlaca,
            $numeroConstanciaMtc,
            $capacidad,
            $tara,
            $largo,
            $ancho,
            $alto
        );
        $updated = VehiculosData::get_vehiculo_by_id($id);

        return ApiResponse::success($updated, 'Vehículo editado correctamente');
    }

    public static function cambiar_estado_vehiculo(int $id, string $estado): array
    {
        VehiculosData::cambiar_estado_vehiculo($id, $estado);
        $updated = VehiculosData::get_vehiculo_by_id($id);

        return ApiResponse::success($updated, 'Estado del vehículo cambiado correctamente');
    }
}
