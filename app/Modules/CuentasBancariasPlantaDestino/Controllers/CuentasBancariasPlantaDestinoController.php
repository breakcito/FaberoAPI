<?php

namespace App\Modules\CuentasBancariasPlantaDestino\Controllers;

use App\Modules\CuentasBancariasPlantaDestino\Services\CuentasBancariasPlantaDestinoService;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CuentasBancariasPlantaDestinoController
{
    public function get_cuentas_bancarias(Request $request, int $id_planta): JsonResponse
    {
        return response()->json(CuentasBancariasPlantaDestinoService::get_cuentas_bancarias($id_planta));
    }

    public function crear_cuenta_bancaria(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_planta_destino' => 'required|integer',
            'id_banco' => 'required|integer',
            'moneda' => 'required|string',
            'numero_cuenta' => 'required|string|max:50',
            'cci' => 'nullable|string|max:50',
            'es_para_detraccion' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()));
        }

        $v = $validator->validated();

        return response()->json(CuentasBancariasPlantaDestinoService::crear_cuenta_bancaria(
            idPlanta: (int) $v['id_planta_destino'],
            idBanco: (int) $v['id_banco'],
            moneda: $v['moneda'],
            numeroCuenta: $v['numero_cuenta'],
            cci: $v['cci'] ?? null,
            esParaDetraccion: (int) $v['es_para_detraccion']
        ));
    }

    public function editar_cuenta_bancaria(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_banco' => 'required|integer',
            'moneda' => 'required|string',
            'numero_cuenta' => 'required|string|max:50',
            'cci' => 'nullable|string|max:50',
            'es_para_detraccion' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()));
        }

        $v = $validator->validated();

        return response()->json(CuentasBancariasPlantaDestinoService::editar_cuenta_bancaria(
            id: $id,
            idBanco: (int) $v['id_banco'],
            moneda: $v['moneda'],
            numeroCuenta: $v['numero_cuenta'],
            cci: $v['cci'] ?? null,
            esParaDetraccion: (int) $v['es_para_detraccion']
        ));
    }

    public function cambiar_estado_cuenta_bancaria(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'estado' => ['required', 'string', Rule::in(['Activo', 'Inactivo'])],
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()));
        }

        return response()->json(CuentasBancariasPlantaDestinoService::cambiar_estado_cuenta_bancaria(
            $id,
            $request->estado
        ));
    }
}
