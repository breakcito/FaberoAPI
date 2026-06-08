<?php

namespace App\Modules\PlantasDestino\Controllers;

use App\Modules\PlantasDestino\Services\PlantasDestinoService;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PlantasDestinoController
{
    public function get_plantas(Request $request): JsonResponse
    {
        return response()->json(PlantasDestinoService::get_plantas());
    }

    public function get_planta(Request $request, int $id): JsonResponse
    {
        return response()->json(PlantasDestinoService::get_planta($id));
    }

    public function crear_planta(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ruc' => 'required|string|size:11',
            'razon_social' => 'required|string|max:255',
            'direccion' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:50',
            'correo' => 'nullable|string|email|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()));
        }

        $v = $validator->validated();

        return response()->json(PlantasDestinoService::crear_planta(
            ruc: $v['ruc'],
            razon_social: $v['razon_social'],
            direccion: $v['direccion'] ?? null,
            telefono: $v['telefono'] ?? null,
            correo: $v['correo'] ?? null
        ));
    }

    public function editar_planta(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ruc' => 'required|string|size:11',
            'razon_social' => 'required|string|max:255',
            'direccion' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:50',
            'correo' => 'nullable|string|email|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()));
        }

        $v = $validator->validated();

        return response()->json(PlantasDestinoService::editar_planta(
            id: $id,
            ruc: $v['ruc'],
            razon_social: $v['razon_social'],
            direccion: $v['direccion'] ?? null,
            telefono: $v['telefono'] ?? null,
            correo: $v['correo'] ?? null
        ));
    }

    public function cambiar_estado_planta(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'estado' => ['required', 'string', Rule::in(['Activo', 'Inactivo'])],
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()));
        }

        return response()->json(PlantasDestinoService::cambiar_estado_planta(
            $id,
            $request->estado
        ));
    }

    /* --- Asociación de Proveedores --- */

    public function get_proveedores_asociados(Request $request, int $id_planta): JsonResponse
    {
        return response()->json(PlantasDestinoService::get_proveedores_asociados($id_planta));
    }

    public function asociar_proveedor(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_planta' => 'required|integer',
            'id_proveedor' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()));
        }

        $v = $validator->validated();

        return response()->json(PlantasDestinoService::asociar_proveedor(
            (int) $v['id_planta'],
            (int) $v['id_proveedor']
        ));
    }

    public function desasociar_proveedor(Request $request, int $id_planta, int $id_proveedor): JsonResponse
    {
        return response()->json(PlantasDestinoService::desasociar_proveedor(
            $id_planta,
            $id_proveedor
        ));
    }
}
