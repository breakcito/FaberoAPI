<?php

namespace App\Services;

use App\Models\ZonaOrigen;
use App\Data\ZonasOrigenData;
use App\Shared\Responses\ApiResponse;

class ZonasOrigenService
{
    /**
     * Obtener todas las zonas de origen
     */
    public static function get_zonas_origen(): array
    {
        $data = ZonasOrigenData::get_zonas_origen();
        return ApiResponse::success($data, 'Zonas de origen obtenidas correctamente');
    }

    /**
     * Crear una nueva zona de origen
     */
    public static function crear_zona_origen(string $nombre): array
    {
        $zona = ZonaOrigen::create([
            'nombre' => trim($nombre),
            'created_at' => now()->toDateTimeString(),
        ]);

        return ApiResponse::success($zona, 'Zona de origen registrada correctamente');
    }
}
