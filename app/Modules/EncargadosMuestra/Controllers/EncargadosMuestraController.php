<?php

namespace App\Modules\EncargadosMuestra\Controllers;

use App\Modules\EncargadosMuestra\Services\EncargadosMuestraService;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class EncargadosMuestraController
{
    public function get_encargados_muestra(Request $request): JsonResponse
    {
        $result = EncargadosMuestraService::get_encargados_muestra();

        return response()->json($result);
    }

    public function crear_encargado_muestra(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'dni' => 'nullable|string|size:8|unique:encargado_muestra,dni',
            'ruc' => 'nullable|string|size:11|unique:encargado_muestra,ruc',
            'nombre' => 'required|string|max:100',
            'apellido' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()));
        }

        $v = $validator->validated();

        $result = EncargadosMuestraService::crear_encargado_muestra(
            dni: $v['dni'] ?? null,
            ruc: $v['ruc'] ?? null,
            nombre: $v['nombre'],
            apellido: $v['apellido']
        );

        return response()->json($result);
    }

    public function editar_encargado_muestra(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'dni' => 'nullable|string|size:8|unique:encargado_muestra,dni,'.$id,
            'ruc' => 'nullable|string|size:11|unique:encargado_muestra,ruc,'.$id,
            'nombre' => 'required|string|max:100',
            'apellido' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()));
        }

        $v = $validator->validated();

        $result = EncargadosMuestraService::editar_encargado_muestra(
            id: $id,
            dni: $v['dni'] ?? null,
            ruc: $v['ruc'] ?? null,
            nombre: $v['nombre'],
            apellido: $v['apellido']
        );

        return response()->json($result);
    }

    public function cambiar_estado_encargado_muestra(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'estado' => ['required', 'string', Rule::in(['Activo', 'Inactivo'])],
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()));
        }

        $v = $validator->validated();

        $result = EncargadosMuestraService::cambiar_estado_encargado_muestra($id, $v['estado']);

        return response()->json($result);
    }
}
