<?php

namespace App\Modules\CuentasBancariasEmpresa\Controllers;

use App\Modules\CuentasBancariasEmpresa\Services\CuentasBancariasEmpresaService;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CuentasBancariasEmpresaController extends Controller
{
    public function get_cuentas_bancarias(Request $request, int $id_empresa): JsonResponse
    {
        return response()->json(CuentasBancariasEmpresaService::get_cuentas_bancarias($id_empresa));
    }

    public function crear_cuenta_bancaria(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_empresa' => 'required|integer',
            'id_banco' => 'required|integer',
            'moneda' => 'required|string',
            'numero_cuenta' => ['required', 'string', 'regex:/^[0-9]{8,20}$/'],
            'cci' => ['nullable', 'string', 'regex:/^[0-9]{20}$/'],
            'es_para_detraccion' => 'required|boolean',
        ], [
            'numero_cuenta.regex' => 'El número de cuenta debe tener entre 8 y 20 dígitos.',
            'cci.regex' => 'El CCI debe tener exactamente 20 dígitos.',
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()));
        }

        return response()->json(CuentasBancariasEmpresaService::crear_cuenta_bancaria(
            (int) $request->id_empresa,
            (int) $request->id_banco,
            $request->moneda,
            $request->numero_cuenta,
            $request->cci,
            (int) $request->es_para_detraccion
        ));
    }

    public function editar_cuenta_bancaria(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_banco' => 'required|integer',
            'moneda' => 'required|string',
            'numero_cuenta' => ['required', 'string', 'regex:/^[0-9]{8,20}$/'],
            'cci' => ['nullable', 'string', 'regex:/^[0-9]{20}$/'],
            'es_para_detraccion' => 'required|boolean',
        ], [
            'numero_cuenta.regex' => 'El número de cuenta debe tener entre 8 y 20 dígitos.',
            'cci.regex' => 'El CCI debe tener exactamente 20 dígitos.',
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()));
        }

        return response()->json(CuentasBancariasEmpresaService::editar_cuenta_bancaria(
            $id,
            (int) $request->id_banco,
            $request->moneda,
            $request->numero_cuenta,
            $request->cci,
            (int) $request->es_para_detraccion
        ));
    }

    public function eliminar_cuenta_bancaria(Request $request, int $id): JsonResponse
    {
        return response()->json(CuentasBancariasEmpresaService::eliminar_cuenta_bancaria($id));
    }

    public function cambiar_estado_cuenta_bancaria(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'estado' => ['required', 'string', Rule::in(['Activo', 'Inactivo'])],
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()));
        }

        return response()->json(CuentasBancariasEmpresaService::cambiar_estado_cuenta_bancaria(
            $id,
            $request->estado
        ));
    }
}
