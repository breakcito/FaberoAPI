<?php

namespace App\Modules\Concesiones;

use App\Shared\Responses\ApiResponse;
use App\Shared\Enums\_Generic\TipoMineral;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ConcesionesController
{
    public function get_concesiones(Request $request): JsonResponse
    {
        $authUser = $request->attributes->get('auth_user');
        $id_usuario = $authUser->id_usuario;
        $result = ConcesionesService::get_concesiones($id_usuario);

        return response()->json($result);
    }

    public function get_empresas(Request $request): JsonResponse
    {
        $result = ConcesionesService::get_empresas();

        return response()->json($result);
    }

    public function crear_concesion(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:255',
            'codigo_concesion' => 'required|string|max:100',
            'codigo_reinfo' => 'nullable|string|max:100',
            'ubigeo' => 'nullable|string|max:100',
            'tipo_mineral' => ['required', Rule::enum(TipoMineral::class)],
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()));
        }

        $v = $validator->validated();

        $result = ConcesionesService::crear_concesion(
            nombre: (string) $v['nombre'],
            codigo_concesion: (string) $v['codigo_concesion'],
            codigo_reinfo: isset($v['codigo_reinfo']) ? (string) $v['codigo_reinfo'] : null,
            ubigeo: isset($v['ubigeo']) ? (string) $v['ubigeo'] : null,
            tipo_mineral: (string) $v['tipo_mineral']
        );

        return response()->json($result);
    }

    public function get_contratos(Request $request, int $id_concesion): JsonResponse
    {
        $result = ConcesionesService::get_contratos($id_concesion);

        return response()->json($result);
    }

    public function crear_contrato(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_concesion' => 'required|integer',
            'id_empresa' => 'required|integer',
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'nullable|date|after_or_equal:fecha_inicio',
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()));
        }

        $v = $validator->validated();

        $result = ConcesionesService::crear_contrato(
            id_concesion: (int) $v['id_concesion'],
            id_empresa: (int) $v['id_empresa'],
            fecha_inicio: (string) $v['fecha_inicio'],
            fecha_fin: isset($v['fecha_fin']) ? (string) $v['fecha_fin'] : null,
        );

        return response()->json($result);
    }

    public function terminar_contrato(Request $request, int $id_contrato): JsonResponse
    {
        $result = ConcesionesService::terminar_contrato($id_contrato);

        return response()->json($result);
    }
}
