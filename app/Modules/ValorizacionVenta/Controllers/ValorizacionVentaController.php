<?php

namespace App\Modules\ValorizacionVenta\Controllers;

use App\Modules\ValorizacionVenta\Services\ValorizacionVentaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ValorizacionVentaController
{
    /**
     * Listar valorizaciones de venta con filtro opcional por planta
     */
    public function listar_valorizaciones(Request $request): JsonResponse
    {
        $idPlanta = $request->query('id_planta') ? (int) $request->query('id_planta') : null;
        $res = ValorizacionVentaService::listar_valorizaciones($idPlanta);

        return response()->json($res);
    }

    /**
     * Obtener una valorización por su ID
     */
    public function obtener_valorizacion(int $id): JsonResponse
    {
        $res = ValorizacionVentaService::obtener_valorizacion($id);
        $status = ($res['success'] ?? false) ? 200 : 404;

        return response()->json($res, $status);
    }

    /**
     * Registrar una nueva valorización en estado Pendiente
     */
    public function crear_valorizacion(Request $request): JsonResponse
    {
        if ($request->has('detalles') && is_string($request->input('detalles'))) {
            $decoded = json_decode($request->input('detalles'), true);
            if (is_array($decoded)) {
                $request->merge(['detalles' => $decoded]);
            }
        }

        $request->validate([
            'id_planta' => 'required|integer|exists:planta_destino,id',
            'detalles' => 'required|array|min:1',
            'detalles.*.id_distribucion_detalle' => 'required|integer|exists:distribucion_detalle,id',
            'detalles.*.elemento_quimico' => 'required|string|in:Oro,Plata',
            'detalles.*.id_condicion_comercial' => 'nullable|integer',
            'detalles.*.id_valor_elemento_quimico' => 'nullable|integer',
            'detalles.*.inter' => 'required|numeric|min:0',
            'detalles.*.des_inter' => 'required|numeric|min:0',
            'detalles.*.recuperacion' => 'required|numeric|min:0|max:100',
            'detalles.*.maquila' => 'required|numeric|min:0',
            'detalles.*.consumo' => 'required|numeric|min:0',
            'detalles.*.factor' => 'nullable|numeric|min:0',
            'evidencias' => 'nullable|array',
            'evidencias.*' => 'file',
            'codigo' => 'nullable|string|max:20',
            'fecha_hora_valorizacion' => 'nullable|date',
            'monto_penalidad' => 'nullable|numeric|min:0',
            'monto_flete' => 'nullable|numeric|min:0',
        ]);

        $authUser = $request->attributes->get('auth_user');
        $idEmpleado = $authUser ? ($authUser->id_empleado ?? $authUser->id_usuario) : 1;

        $payload = [
            'id_planta' => (int) $request->input('id_planta'),
            'id_empleado_registro' => (int) $idEmpleado,
            'detalles' => $request->input('detalles'),
            'codigo' => $request->input('codigo') ?: null,
            'fecha_hora_valorizacion' => $request->input('fecha_hora_valorizacion') ?: null,
            'monto_penalidad' => $request->input('monto_penalidad') !== null ? (float) $request->input('monto_penalidad') : 0,
            'monto_flete' => $request->input('monto_flete') !== null ? (float) $request->input('monto_flete') : 0,
        ];

        $archivos = [];
        if ($request->hasFile('evidencias')) {
            $archivos = $request->file('evidencias');
            if (! is_array($archivos)) {
                $archivos = [$archivos];
            }
        }

        $res = ValorizacionVentaService::crear_valorizacion($payload, $archivos);
        $status = ($res['success'] ?? false) ? 201 : 400;

        return response()->json($res, $status);
    }

    /**
     * Editar una valorización existente en estado Pendiente
     */
    public function editar_valorizacion(Request $request, int $id): JsonResponse
    {
        if ($request->has('detalles') && is_string($request->input('detalles'))) {
            $decoded = json_decode($request->input('detalles'), true);
            if (is_array($decoded)) {
                $request->merge(['detalles' => $decoded]);
            }
        }

        $request->validate([
            'id_planta' => 'required|integer|exists:planta_destino,id',
            'detalles' => 'required|array|min:1',
            'detalles.*.id_distribucion_detalle' => 'required|integer|exists:distribucion_detalle,id',
            'detalles.*.elemento_quimico' => 'required|string|in:Oro,Plata',
            'detalles.*.id_condicion_comercial' => 'nullable|integer',
            'detalles.*.id_valor_elemento_quimico' => 'nullable|integer',
            'detalles.*.inter' => 'required|numeric|min:0',
            'detalles.*.des_inter' => 'required|numeric|min:0',
            'detalles.*.recuperacion' => 'required|numeric|min:0|max:100',
            'detalles.*.maquila' => 'required|numeric|min:0',
            'detalles.*.consumo' => 'required|numeric|min:0',
            'detalles.*.factor' => 'nullable|numeric|min:0',
            'evidencias' => 'nullable|array',
            'evidencias.*' => 'file',
            'evidencias_existentes' => 'nullable|string',
            'codigo' => 'nullable|string|max:20',
            'fecha_hora_valorizacion' => 'nullable|date',
            'monto_penalidad' => 'nullable|numeric|min:0',
            'monto_flete' => 'nullable|numeric|min:0',
        ]);

        $authUser = $request->attributes->get('auth_user');
        $idEmpleado = $authUser ? ($authUser->id_empleado ?? $authUser->id_usuario) : 1;

        $payload = [
            'id_planta' => (int) $request->input('id_planta'),
            'id_empleado_edicion' => (int) $idEmpleado,
            'detalles' => $request->input('detalles'),
            'evidencias_existentes' => $request->input('evidencias_existentes'),
            'codigo' => $request->input('codigo') ?: null,
            'fecha_hora_valorizacion' => $request->input('fecha_hora_valorizacion') ?: null,
            'monto_penalidad' => $request->input('monto_penalidad') !== null ? (float) $request->input('monto_penalidad') : 0,
            'monto_flete' => $request->input('monto_flete') !== null ? (float) $request->input('monto_flete') : 0,
        ];

        $archivos = [];
        if ($request->hasFile('evidencias')) {
            $archivos = $request->file('evidencias');
            if (! is_array($archivos)) {
                $archivos = [$archivos];
            }
        }

        $res = ValorizacionVentaService::editar_valorizacion($id, $payload, $archivos);
        $status = ($res['success'] ?? false) ? 200 : 400;

        return response()->json($res, $status);
    }

    /**
     * Aprobar valorización (enciende flags esta_valorizado_X por elemento)
     */
    public function aprobar_valorizacion(Request $request, int $id): JsonResponse
    {
        $authUser = $request->attributes->get('auth_user');
        $idEmpleado = $authUser ? ($authUser->id_empleado ?? $authUser->id_usuario) : 1;

        $res = ValorizacionVentaService::aprobar_valorizacion($id, (int) $idEmpleado);
        $status = ($res['success'] ?? false) ? 200 : 400;

        return response()->json($res, $status);
    }

    /**
     * Anular o eliminar una valorización.
     * El campo motivo_anulacion se acepta pero no se persiste (columna no existe en el schema actual).
     */
    public function anular_valorizacion(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'motivo_anulacion' => 'required|string|min:3',
            'tipo_eliminacion' => 'required|string|in:logica,fisica',
        ]);

        $authUser = $request->attributes->get('auth_user');
        $idEmpleado = $authUser ? ($authUser->id_empleado ?? $authUser->id_usuario) : 1;

        $motivoAnulacion = (string) $request->input('motivo_anulacion');
        $tipoEliminacion = (string) $request->input('tipo_eliminacion', 'logica');

        $res = ValorizacionVentaService::anular_valorizacion(
            $id,
            (int) $idEmpleado,
            $motivoAnulacion,
            $tipoEliminacion
        );
        $status = ($res['success'] ?? false) ? 200 : 400;

        return response()->json($res, $status);
    }
}
