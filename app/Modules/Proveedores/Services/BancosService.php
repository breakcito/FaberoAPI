<?php

namespace App\Modules\Proveedores\Services;

use App\Models\Banco;
use App\Shared\Responses\ApiResponse;
use App\Modules\Proveedores\Data\BancosData;

class BancosService
{
    public static function get_bancos(): array
    {
        $data = BancosData::get_bancos();
        return ApiResponse::success($data, "Bancos obtenidos correctamente");
    }

    public static function crear_banco(string $nombre, string $abreviatura): array
    {
        $existe = Banco::where('nombre', $nombre)
            ->orWhere('abreviatura', $abreviatura)
            ->exists();

        if ($existe) {
            return ApiResponse::error("El banco con ese nombre o abreviatura ya existe");
        }

        $id = BancosData::crear_banco($nombre, $abreviatura);
        $nuevoBanco = BancosData::get_banco_by_id($id);
        return ApiResponse::success($nuevoBanco, "Banco creado correctamente");
    }
}
