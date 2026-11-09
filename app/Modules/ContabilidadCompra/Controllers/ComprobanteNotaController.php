<?php

namespace App\Modules\ContabilidadCompra\Controllers;

use App\Modules\ContabilidadCompra\Services\ComprobanteNotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ComprobanteNotaController extends Controller
{
    /**
     * Listar las notas de crédito / débito de un comprobante.
     */
    public function listar_notas(int $id): JsonResponse
    {
        return response()->json(ComprobanteNotaService::listar_notas($id));
    }

    /**
     * Registrar una nota de crédito o débito sobre un comprobante.
     */
    public function registrar_nota(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'tipo' => 'required|string|in:Credito,Debito',
            'monto' => 'required|numeric|gt:0',
            'codigo_nota' => 'nullable|string|max:50',
            'motivo' => 'required|string|max:50',
            'fecha_emision' => 'required|date_format:Y-m-d',
            'documentos' => 'nullable|array',
            'documentos.*' => 'file',
        ]);

        $authUser = $request->attributes->get('auth_user');
        $idEmpleado = $authUser ? ($authUser->id_empleado ?? $authUser->id_usuario) : 1;

        $payload = [
            'tipo' => (string) $request->input('tipo'),
            'monto' => (float) $request->input('monto'),
            'codigo_nota' => $request->input('codigo_nota'),
            'motivo' => (string) $request->input('motivo'),
            'fecha_emision' => (string) $request->input('fecha_emision'),
            'id_empleado_registro' => (int) $idEmpleado,
        ];

        $archivos = [];
        if ($request->hasFile('documentos')) {
            $archivos = $request->file('documentos');
            if (! is_array($archivos)) {
                $archivos = [$archivos];
            }
        }

        $res = ComprobanteNotaService::registrar_nota($id, $payload, $archivos);
        $status = ($res['success'] ?? false) ? 201 : 400;

        return response()->json($res, $status);
    }

    /**
     * Anular una nota de crédito o débito.
     */
    public function anular_nota(Request $request, int $id): JsonResponse
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

        $res = ComprobanteNotaService::anular_nota($id, $payload);
        $status = ($res['success'] ?? false) ? 200 : 400;

        return response()->json($res, $status);
    }
}
