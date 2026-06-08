<?php

namespace App\Modules\Marcas\Services;

use App\Models\Marca;
use App\Modules\Marcas\Data\MarcasData;
use App\Shared\Responses\ApiResponse;

class MarcasService
{
    public static function get_marcas(): array
    {
        $data = MarcasData::get_marcas();

        return ApiResponse::success($data, 'Marcas obtenidas correctamente');
    }

    public static function crear_marca(string $nombre): array
    {
        $existe = Marca::where('nombre', $nombre)->exists();
        if ($existe) {
            return ApiResponse::error('La marca ya existe');
        }

        $id = MarcasData::crear_marca($nombre);
        $nuevaMarca = MarcasData::get_marca_by_id($id);

        return ApiResponse::success($nuevaMarca, 'Marca creada correctamente');
    }

    public static function editar_marca(int $id, string $nombre): array
    {
        $existe = Marca::where('nombre', $nombre)->where('id', '!=', $id)->exists();
        if ($existe) {
            return ApiResponse::error('Ya existe otra marca con ese nombre');
        }

        MarcasData::editar_marca($id, $nombre);
        $updated = MarcasData::get_marca_by_id($id);

        return ApiResponse::success($updated, 'Marca editada correctamente');
    }

    public static function cambiar_estado_marca(int $id, string $estado): array
    {
        MarcasData::cambiar_estado_marca($id, $estado);
        $updated = MarcasData::get_marca_by_id($id);

        return ApiResponse::success($updated, 'Estado de la marca cambiado correctamente');
    }
}
