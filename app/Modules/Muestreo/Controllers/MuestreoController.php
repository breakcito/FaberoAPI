<?php

namespace App\Modules\Muestreo\Controllers;

use App\Modules\Muestreo\Services\MuestreoService;
use App\Shared\Enums\_Generic\TipoMineral;
use App\Shared\Enums\_Generic\TipoProducto;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MuestreoController
{
    /**
     * Listado de lotes pendientes de muestreo.
     */
    public function get_lotes(Request $request): JsonResponse
    {
        $filtros = [
            'fecha_inicio' => $request->input('fecha_inicio'),
            'fecha_fin' => $request->input('fecha_fin'),
        ];

        return response()->json(MuestreoService::get_lotes($filtros));
    }

    /**
     * Detalle de un lote pendiente de muestreo.
     */
    public function get_lote(Request $request, int|string $id): JsonResponse
    {
        return response()->json(MuestreoService::get_lote((int) $id));
    }

    /**
     * Confirmar el muestreo de un lote: tipo de producto, tipo de mineral y
     * correlativo (editable).
     */
    public function confirmar_muestreo(Request $request, int|string $id): JsonResponse
    {
        $authUser = $request->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado.'), 401);
        }

        $request->validate([
            'tipo_producto' => ['required', 'string', Rule::enum(TipoProducto::class)],
            'tipo_mineral' => ['required', 'string', Rule::enum(TipoMineral::class)],
            'correlativo' => ['required', 'string', 'max:50'],
            'numero_correlativo' => ['nullable', 'integer', 'min:1'],
        ]);

        $data = $request->only([
            'tipo_producto',
            'tipo_mineral',
            'correlativo',
            'numero_correlativo',
        ]);

        return response()->json(MuestreoService::confirmar_muestreo(
            (int) $id,
            (int) $authUser->id_empleado,
            $data,
        ));
    }
}
