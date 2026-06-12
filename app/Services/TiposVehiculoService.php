<?php

namespace App\Services;

use App\Models\TipoVehiculo;
use App\Data\TiposVehiculoData;
use App\Shared\Responses\ApiResponse;

class TiposVehiculoService
{
    public static function get_tipos_vehiculo(?int $id = null): array
    {
        $data = TiposVehiculoData::get_tipos_vehiculo($id);

        return ApiResponse::success($data, 'Tipos de vehículo obtenidos correctamente');
    }

    public static function crear_tipo_vehiculo(string $nombre, bool $tieneCarreta, bool $esCarreta): array
    {
        $existe = TipoVehiculo::where('nombre', $nombre)->exists();
        if ($existe) {
            return ApiResponse::error('El tipo de vehículo ya existe');
        }

        $id = TiposVehiculoData::crear_tipo_vehiculo($nombre, $tieneCarreta, $esCarreta);
        $nuevoTipo = TiposVehiculoData::get_tipos_vehiculo($id);

        return ApiResponse::success($nuevoTipo, 'Tipo de vehículo creado correctamente');
    }

    public static function editar_tipo_vehiculo(int $id, string $nombre, bool $tieneCarreta, bool $esCarreta): array
    {
        $existe = TipoVehiculo::where('nombre', $nombre)->where('id', '!=', $id)->exists();
        if ($existe) {
            return ApiResponse::error('Ya existe otro tipo de vehículo con ese nombre');
        }

        TiposVehiculoData::editar_tipo_vehiculo($id, $nombre, $tieneCarreta, $esCarreta);
        $updated = TiposVehiculoData::get_tipos_vehiculo($id);

        return ApiResponse::success($updated, 'Tipo de vehículo editado correctamente');
    }

    public static function cambiar_estado_tipo_vehiculo(int $id, string $estado): array
    {
        TiposVehiculoData::cambiar_estado_tipo_vehiculo($id, $estado);
        $updated = TiposVehiculoData::get_tipos_vehiculo($id);

        return ApiResponse::success($updated, 'Estado del tipo de vehículo cambiado correctamente');
    }
}
