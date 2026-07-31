<?php

namespace App\Modules\ContabilidadCompra\Controllers;

use App\Modules\ContabilidadCompra\Services\ContabilidadCompraService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ContabilidadCompraController extends Controller
{
    /**
     * Listar comprobantes de compra con filtros opcionales.
     */
    public function listar_comprobantes(Request $request): JsonResponse
    {
        $idProveedor = $request->query('id_proveedor') ? (int) $request->query('id_proveedor') : null;
        $estado = $request->query('estado');
        $fechaInicio = $request->query('fecha_inicio');
        $fechaFin = $request->query('fecha_fin');

        return response()->json(ContabilidadCompraService::listar_comprobantes($idProveedor, $estado, $fechaInicio, $fechaFin));
    }

    /**
     * Obtener un comprobante por su ID.
     */
    public function obtener_comprobante(int $id): JsonResponse
    {
        $res = ContabilidadCompraService::obtener_comprobante($id);
        $status = ($res['success'] ?? false) ? 200 : 404;

        return response()->json($res, $status);
    }

    /**
     * Crear un comprobante a partir de una valorización aprobada.
     */
    public function crear_comprobante(Request $request): JsonResponse
    {
        // Decodificar evidencias[] si vienen como JSON string (multipart)
        if ($request->has('evidencias') && is_string($request->input('evidencias'))) {
            $decoded = json_decode($request->input('evidencias'), true);
            if (is_array($decoded)) {
                $request->merge(['evidencias' => $decoded]);
            }
        }

        $request->validate([
            'id_valorizacion_compra' => 'required|integer|exists:valorizacion_compra,id',
            'serie' => 'required|string|max:10',
            'numero' => 'required|string|max:20',
            'fecha_emision' => 'required|date_format:Y-m-d',
            'porcentaje_igv' => 'nullable|numeric|min:0|max:1',
            'porcentaje_detraccion' => 'nullable|numeric|min:0|max:1',
            'evidencias' => 'nullable|array',
            'evidencias.*' => 'file',
        ]);

        $authUser = $request->attributes->get('auth_user');
        $idEmpleado = $authUser ? ($authUser->id_empleado ?? $authUser->id_usuario) : 1;

        $payload = [
            'id_valorizacion_compra' => (int) $request->input('id_valorizacion_compra'),
            'serie' => (string) $request->input('serie'),
            'numero' => (string) $request->input('numero'),
            'fecha_emision' => (string) $request->input('fecha_emision'),
            'porcentaje_igv' => $request->input('porcentaje_igv'),
            'porcentaje_detraccion' => $request->input('porcentaje_detraccion'),
            'id_empleado_registro' => (int) $idEmpleado,
        ];

        $archivos = [];
        if ($request->hasFile('evidencias')) {
            $archivos = $request->file('evidencias');
            if (! is_array($archivos)) {
                $archivos = [$archivos];
            }
        }

        $res = ContabilidadCompraService::crear_comprobante($payload, $archivos);
        $status = ($res['success'] ?? false) ? 201 : 400;

        return response()->json($res, $status);
    }

    /**
     * Otorgar una aprobación (Contabilidad / Comercial / Documentaria) sobre un comprobante.
     */
    public function aprobar_comprobante(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'tipo' => 'required|string|in:Contabilidad,Comercial,Documentaria',
        ]);

        $authUser = $request->attributes->get('auth_user');
        $idEmpleado = $authUser ? ($authUser->id_empleado ?? $authUser->id_usuario) : 1;

        $payload = [
            'tipo' => (string) $request->input('tipo'),
            'id_empleado' => (int) $idEmpleado,
        ];

        $res = ContabilidadCompraService::aprobar_comprobante($id, $payload);
        $status = ($res['success'] ?? false) ? 200 : 400;

        return response()->json($res, $status);
    }

    /**
     * Anular un comprobante (en cascada, también anula sus pagos vigentes).
     */
    public function anular_comprobante(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'motivo' => 'required|string|min:3|max:500',
        ]);

        $authUser = $request->attributes->get('auth_user');
        $idEmpleado = $authUser ? ($authUser->id_empleado ?? $authUser->id_usuario) : 1;

        $payload = [
            'motivo' => (string) $request->input('motivo'),
            'id_empleado_anulacion' => (int) $idEmpleado,
        ];

        $res = ContabilidadCompraService::anular_comprobante($id, $payload);
        $status = ($res['success'] ?? false) ? 200 : 400;

        return response()->json($res, $status);
    }

    /**
     * Listar pagos de un comprobante.
     */
    public function listar_pagos(int $id): JsonResponse
    {
        return response()->json(ContabilidadCompraService::listar_pagos($id));
    }

    /**
     * Registrar un nuevo pago sobre un comprobante.
     */
    public function registrar_pago(Request $request, int $id): JsonResponse
    {
        if ($request->has('evidencias') && is_string($request->input('evidencias'))) {
            $decoded = json_decode($request->input('evidencias'), true);
            if (is_array($decoded)) {
                $request->merge(['evidencias' => $decoded]);
            }
        }

        $request->validate([
            'id_cuenta_bancaria_empresa' => 'nullable|integer|exists:cuenta_bancaria_empresa,id',
            'id_cuenta_bancaria_proveedor' => 'nullable|integer|exists:cuenta_bancaria_proveedor,id',
            'es_para_detraccion' => 'required|boolean',
            'medio_pago' => 'required|string|in:Transferencia,Depósito,Efectivo',
            'monto_pagado' => 'required|numeric|gt:0',
            'fecha_hora_pago' => 'nullable|date',
            'numero_operacion' => 'nullable|string|max:50',
            'observacion' => 'nullable|string|max:500',
            'evidencias' => 'nullable|array',
            'evidencias.*' => 'file',
        ]);

        $authUser = $request->attributes->get('auth_user');
        $idEmpleado = $authUser ? ($authUser->id_empleado ?? $authUser->id_usuario) : 1;

        $payload = [
            'id_cuenta_bancaria_empresa' => $request->input('id_cuenta_bancaria_empresa'),
            'id_cuenta_bancaria_proveedor' => $request->input('id_cuenta_bancaria_proveedor'),
            'es_para_detraccion' => (bool) $request->input('es_para_detraccion'),
            'medio_pago' => (string) $request->input('medio_pago'),
            'monto_pagado' => (float) $request->input('monto_pagado'),
            'fecha_hora_pago' => $request->input('fecha_hora_pago') ?: now()->toDateTimeString(),
            'numero_operacion' => $request->input('numero_operacion'),
            'observacion' => $request->input('observacion'),
            'id_empleado_registro' => (int) $idEmpleado,
        ];

        $archivos = [];
        if ($request->hasFile('evidencias')) {
            $archivos = $request->file('evidencias');
            if (! is_array($archivos)) {
                $archivos = [$archivos];
            }
        }

        $res = ContabilidadCompraService::registrar_pago($id, $payload, $archivos);
        $status = ($res['success'] ?? false) ? 201 : 400;

        return response()->json($res, $status);
    }

    /**
     * Anular un pago (revierte el dinero al comprobante).
     */
    public function anular_pago(Request $request, int $id): JsonResponse
    {
        if ($request->has('evidencias_anulacion') && is_string($request->input('evidencias_anulacion'))) {
            $decoded = json_decode($request->input('evidencias_anulacion'), true);
            if (is_array($decoded)) {
                $request->merge(['evidencias_anulacion' => $decoded]);
            }
        }

        $request->validate([
            'motivo' => 'required|string|min:3|max:500',
            'evidencias_anulacion' => 'nullable|array',
            'evidencias_anulacion.*' => 'file',
        ]);

        $authUser = $request->attributes->get('auth_user');
        $idEmpleado = $authUser ? ($authUser->id_empleado ?? $authUser->id_usuario) : 1;

        $payload = [
            'motivo' => (string) $request->input('motivo'),
            'id_empleado_anulacion' => (int) $idEmpleado,
        ];

        $archivos = [];
        if ($request->hasFile('evidencias_anulacion')) {
            $archivos = $request->file('evidencias_anulacion');
            if (! is_array($archivos)) {
                $archivos = [$archivos];
            }
        }

        $res = ContabilidadCompraService::anular_pago($id, $payload, $archivos);
        $status = ($res['success'] ?? false) ? 200 : 400;

        return response()->json($res, $status);
    }
}
