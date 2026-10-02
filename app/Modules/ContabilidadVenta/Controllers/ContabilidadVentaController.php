<?php

namespace App\Modules\ContabilidadVenta\Controllers;

use App\Modules\ContabilidadVenta\Services\ContabilidadVentaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ContabilidadVentaController extends Controller
{
    /**
     * Listar comprobantes de venta con filtros opcionales.
     */
    public function listar_comprobantes(Request $request): JsonResponse
    {
        $idPlanta = $request->query('id_planta') ? (int) $request->query('id_planta') : null;
        $estado = $request->query('estado');
        $fechaInicio = $request->query('fecha_inicio');
        $fechaFin = $request->query('fecha_fin');

        return response()->json(ContabilidadVentaService::listar_comprobantes($idPlanta, $estado, $fechaInicio, $fechaFin));
    }

    /**
     * Obtener un comprobante por ID.
     */
    public function obtener_comprobante(int $id): JsonResponse
    {
        $res = ContabilidadVentaService::obtener_comprobante($id);
        $status = ($res['success'] ?? false) ? 200 : 404;

        return response()->json($res, $status);
    }

    /**
     * Obtener detalles de valorización de venta disponibles para una planta.
     */
    public function get_detalles_disponibles(Request $request): JsonResponse
    {
        $idPlanta = (int) $request->query('id_planta', 0);
        if ($idPlanta <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'El id_planta es obligatorio.',
                'data' => [],
            ], 422);
        }

        return response()->json(ContabilidadVentaService::get_detalles_disponibles($idPlanta));
    }

    /**
     * Obtener anticipos disponibles con saldo para una planta.
     */
    public function get_anticipos_disponibles(Request $request): JsonResponse
    {
        $idPlanta = (int) $request->query('id_planta', 0);
        if ($idPlanta <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'El id_planta es obligatorio.',
                'data' => [],
            ], 422);
        }

        return response()->json(ContabilidadVentaService::get_anticipos_disponibles($idPlanta));
    }

    /**
     * Crear un comprobante de venta.
     */
    public function crear_comprobante(Request $request): JsonResponse
    {
        // Parsear JSON strings si vienen de FormData
        if ($request->has('detalles_ids') && is_string($request->input('detalles_ids'))) {
            $decoded = json_decode($request->input('detalles_ids'), true);
            if (is_array($decoded)) {
                $request->merge(['detalles_ids' => $decoded]);
            }
        }

        if ($request->has('anticipos') && is_string($request->input('anticipos'))) {
            $decoded = json_decode($request->input('anticipos'), true);
            if (is_array($decoded)) {
                $request->merge(['anticipos' => $decoded]);
            }
        }

        $request->validate([
            'id_planta_destino' => 'required|integer|exists:planta_destino,id',
            'id_empresa' => 'nullable|integer',
            'id_tipo_cambio' => 'required|integer|exists:tipo_cambio,id',
            'codigo_comprobante' => 'required|string|max:50',
            'fecha_emision' => 'required|date_format:Y-m-d',
            'detalles_ids' => 'required|array|min:1',
            'detalles_ids.*' => 'integer|exists:valorizacion_venta_detalle,id',
            'monto_penalidad' => 'nullable|numeric|min:0',
            'monto_flete' => 'nullable|numeric|min:0',
            'percentaje_igv' => 'nullable|numeric|min:0|max:1',
            'porcentaje_detraccion' => 'nullable|numeric|min:0|max:1',
            'anticipos' => 'nullable|array',
            'anticipos.*.id_anticipo_planta' => 'required_with:anticipos|integer|exists:anticipo_planta,id',
            'anticipos.*.monto_retirado' => 'required_with:anticipos|numeric|min:0.01',
            'evidencias' => 'nullable|array',
            'evidencias.*' => 'file',
        ]);

        $authUser = $request->attributes->get('auth_user');
        $idEmpleado = $authUser->id_empleado ?? auth()->id() ?? 1;

        $payload = $request->only([
            'id_planta_destino',
            'id_empresa',
            'id_tipo_cambio',
            'codigo_comprobante',
            'fecha_emision',
            'detalles_ids',
            'monto_penalidad',
            'monto_flete',
            'percentaje_igv',
            'porcentaje_detraccion',
            'anticipos',
        ]);
        $payload['id_empleado_registro'] = (int) $idEmpleado;

        /** @var \Illuminate\Http\UploadedFile[] $archivos */
        $archivos = $request->file('evidencias', []);

        $res = ContabilidadVentaService::crear_comprobante($payload, $archivos);
        $status = ($res['success'] ?? false) ? 200 : 422;

        return response()->json($res, $status);
    }

    /**
     * Anular un comprobante de venta.
     */
    public function anular_comprobante(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'motivo' => 'required|string|min:3|max:500',
            'evidencias' => 'nullable|array',
            'evidencias.*' => 'file',
        ]);

        $authUser = $request->attributes->get('auth_user');
        $idEmpleado = $authUser->id_empleado ?? auth()->id() ?? 1;

        $payload = $request->only(['motivo']);
        $payload['id_empleado_anulacion'] = (int) $idEmpleado;

        /** @var \Illuminate\Http\UploadedFile[] $archivos */
        $archivos = $request->file('evidencias', []);

        $res = ContabilidadVentaService::anular_comprobante($id, $payload, $archivos);
        $status = ($res['success'] ?? false) ? 200 : 422;

        return response()->json($res, $status);
    }

    /**
     * Listar pagos de un comprobante de venta.
     */
    public function listar_pagos(int $id): JsonResponse
    {
        return response()->json(ContabilidadVentaService::listar_pagos($id));
    }

    /**
     * Registrar un pago para un comprobante de venta.
     */
    public function registrar_pago(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'id_cuenta_bancaria_planta' => 'nullable|integer|exists:cuenta_bancaria_planta_destino,id',
            'id_cuenta_bancaria_empresa' => 'nullable|integer|exists:cuenta_bancaria_empresa,id',
            'es_para_detraccion' => 'required|boolean',
            'medio_pago' => 'required|string|in:Transferencia,Depósito,Efectivo',
            'monto_pagado' => 'required|numeric|min:0.01',
            'fecha_hora_pago' => 'required|date',
            'numero_operacion' => 'nullable|string|max:50',
            'observacion' => 'nullable|string|max:500',
            'evidencias' => 'nullable|array',
            'evidencias.*' => 'file',
        ]);

        $authUser = $request->attributes->get('auth_user');
        $idEmpleado = $authUser->id_empleado ?? auth()->id() ?? 1;

        $payload = $request->only([
            'id_cuenta_bancaria_planta',
            'id_cuenta_bancaria_empresa',
            'es_para_detraccion',
            'medio_pago',
            'monto_pagado',
            'fecha_hora_pago',
            'numero_operacion',
            'observacion',
        ]);
        $payload['id_empleado_registro'] = (int) $idEmpleado;

        /** @var \Illuminate\Http\UploadedFile[] $archivos */
        $archivos = $request->file('evidencias', []);

        $res = ContabilidadVentaService::registrar_pago($id, $payload, $archivos);
        $status = ($res['success'] ?? false) ? 200 : 422;

        return response()->json($res, $status);
    }

    /**
     * Anular un pago de comprobante de venta.
     */
    public function anular_pago(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'motivo' => 'required|string|min:3|max:500',
            'evidencias_anulacion' => 'nullable|array',
            'evidencias_anulacion.*' => 'file',
        ]);

        $authUser = $request->attributes->get('auth_user');
        $idEmpleado = $authUser->id_empleado ?? auth()->id() ?? 1;

        $payload = $request->only(['motivo']);
        $payload['id_empleado_anulacion'] = (int) $idEmpleado;

        /** @var \Illuminate\Http\UploadedFile[] $archivos */
        $archivos = $request->file('evidencias_anulacion', []);

        $res = ContabilidadVentaService::anular_pago($id, $payload, $archivos);
        $status = ($res['success'] ?? false) ? 200 : 422;

        return response()->json($res, $status);
    }
}
