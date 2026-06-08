<?php

namespace App\Modules\EncargadosMuestra\Services;

use App\Modules\EncargadosMuestra\Data\EncargadosMuestraData;
use App\Shared\Responses\ApiResponse;

class EncargadosMuestraService
{
    public static function get_encargados_muestra(): array|object
    {
        $data = EncargadosMuestraData::get_encargados_muestra();

        return ApiResponse::success($data, 'Encargados de muestra obtenidos correctamente');
    }

    public static function crear_encargado_muestra(
        ?string $dni,
        ?string $ruc,
        string $nombre,
        string $apellido
    ): array|object {
        $id = EncargadosMuestraData::crear_encargado_muestra($dni, $ruc, $nombre, $apellido);
        $new_handler = EncargadosMuestraData::get_encargado_muestra_by_id($id);

        return ApiResponse::success($new_handler, 'Encargado de muestra registrado correctamente');
    }

    public static function editar_encargado_muestra(
        int $id,
        ?string $dni,
        ?string $ruc,
        string $nombre,
        string $apellido
    ): array|object {
        EncargadosMuestraData::editar_encargado_muestra($id, $dni, $ruc, $nombre, $apellido);
        $updated = EncargadosMuestraData::get_encargado_muestra_by_id($id);

        return ApiResponse::success($updated, 'Encargado de muestra editado correctamente');
    }

    public static function cambiar_estado_encargado_muestra(int $id, string $estado): array|object
    {
        EncargadosMuestraData::cambiar_estado_encargado_muestra($id, $estado);
        $updated = EncargadosMuestraData::get_encargado_muestra_by_id($id);

        return ApiResponse::success($updated, 'Estado del encargado de muestra cambiado correctamente');
    }
}
