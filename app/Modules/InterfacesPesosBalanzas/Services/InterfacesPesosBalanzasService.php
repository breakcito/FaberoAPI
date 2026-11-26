<?php

namespace App\Modules\InterfacesPesosBalanzas\Services;

use App\Modules\InterfacesPesosBalanzas\Data\InterfacesPesosBalanzasData;
use App\Shared\Responses\ApiResponse;

class InterfacesPesosBalanzasService
{
    /**
     * Peso publicado por una balanza.
     *
     * `null` significa que la balanza no tiene lectura pendiente (centinela
     * `-1`) o que el valor publicado no es numérico.
     */
    public static function get_peso(int $codBalanza): array
    {
        return ApiResponse::success([
            'cod_balanza' => $codBalanza,
            'peso' => InterfacesPesosBalanzasData::get_peso($codBalanza),
            'descripcion' => InterfacesPesosBalanzasData::get_descripcion($codBalanza),
        ], 'Peso de la balanza obtenido correctamente.');
    }

    /**
     * Devuelve la balanza a su estado de espera (`peso = '-1'`).
     *
     * Se invoca después de registrar el peso de un lote o una partición:
     * la balanza queda libre para publicar la siguiente lectura.
     */
    public static function liberar(int $codBalanza): array
    {
        InterfacesPesosBalanzasData::liberar($codBalanza);

        return ApiResponse::success(
            ['cod_balanza' => $codBalanza, 'peso' => null],
            'Balanza liberada correctamente.'
        );
    }
}
