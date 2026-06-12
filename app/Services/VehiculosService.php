<?php

namespace App\Services;

use App\Data\VehiculosData;
use App\Shared\Responses\ApiResponse;

class VehiculosService
{
    /**
     * Obtener listado global de vehículos (datos simplificados)
     */
    public static function get_vehiculos(?string $seriePlaca = null, ?string $numeroPlaca = null, ?int $id = null): array
    {
        $data = VehiculosData::get_vehiculos($seriePlaca, $numeroPlaca, $id);
        return ApiResponse::success($data);
    }

    /**
     * Crear un nuevo vehículo de forma simplificada
     */
    public static function crear_vehiculo_simplificado(
        ?string $seriePlaca,
        string $numeroPlaca,
        int $idEmpresaTransporte,
        int $idTipoVehiculo
    ): array {
        $id = VehiculosData::crear_vehiculo_simplificado($seriePlaca, $numeroPlaca, $idEmpresaTransporte, $idTipoVehiculo);
        $vehiculos = VehiculosData::get_vehiculos(id: $id);
        return ApiResponse::success($vehiculos[0] ?? null, 'Vehículo registrado correctamente');
    }

    /**
     * Editar un vehículo de forma simplificada
     */
    public static function editar_vehiculo_simplificado(
        int $id,
        int $idEmpresaTransporte,
        int $idTipoVehiculo
    ): array {
        VehiculosData::editar_vehiculo_simplificado($id, $idEmpresaTransporte, $idTipoVehiculo);
        $vehiculos = VehiculosData::get_vehiculos(id: $id);
        return ApiResponse::success($vehiculos[0] ?? null, 'Vehículo actualizado correctamente');
    }
}
