<?php

namespace App\Modules\InterfacesPesosBalanzas\Controllers;

use App\Modules\InterfacesPesosBalanzas\Services\InterfacesPesosBalanzasService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InterfacesPesosBalanzasController
{
    /**
     * Peso publicado por la balanza (`null` si no hay lectura pendiente).
     *
     * El código de balanza es opcional: si no se envía, se usa el
     * configurado en `config/balanza.php`.
     */
    public function get_peso(Request $request): JsonResponse
    {
        return response()->json(
            InterfacesPesosBalanzasService::get_peso(
                self::resolverCodBalanza($request)
            )
        );
    }

    /**
     * Devuelve la balanza a su estado de espera (`peso = '-1'`).
     */
    public function liberar(Request $request): JsonResponse
    {
        return response()->json(
            InterfacesPesosBalanzasService::liberar(
                self::resolverCodBalanza($request)
            )
        );
    }

    /**
     * Código de balanza solicitado o, si no viene, el configurado.
     */
    private static function resolverCodBalanza(Request $request): int
    {
        $cod = $request->query('cod_balanza');

        if ($cod !== null && $cod !== '') {
            return (int) $cod;
        }

        return (int) config('balanza.cod_balanza', 1);
    }
}
