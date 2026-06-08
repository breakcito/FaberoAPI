<?php

namespace App\Modules\Sucursales\Service;

use App\Modules\Sucursales\Data\SucursalesData;
use App\Shared\Responses\ApiResponse;

class SucursalesService
{
    /**
     * Obtener listado de todas las sucursales
     */
    public static function get_sucursales(): array
    {
        $data = SucursalesData::get_sucursales();

        return ApiResponse::success($data);
    }

    /**
     * Crear una nueva sucursal si no existe otra con el mismo nombre
     */
    public static function crear_sucursal(
        string $nombre,
        //
        ?int $id_departamento = null,
        ?int $id_provincia = null,
        ?int $id_distrito = null,
        ?string $direccion = null,
        ?string $telefono = null
    ): array {
        // Validar si ya existe en base al nombre
        $ya_existe = SucursalesData::existe_sucursal($nombre);
        if ($ya_existe) {
            return ApiResponse::error('Ya existe una sucursal con ese nombre');
        }

        // si no existe, lo creamos
        $id = SucursalesData::crear_sucursal(
            $nombre,
            $id_departamento,
            $id_provincia,
            $id_distrito,
            $direccion,
            $telefono,
        );

        $new_sucursal = SucursalesData::get_sucursales(id_sucursal: $id);

        return ApiResponse::success($new_sucursal, 'Sucursal registrada correctamente');
    }
}
