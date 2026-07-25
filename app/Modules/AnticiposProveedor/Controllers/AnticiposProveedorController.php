<?php

namespace App\Modules\AnticiposProveedor\Controllers;

use App\Modules\AnticiposProveedor\Services\AnticiposProveedorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnticiposProveedorController
{
    /**
     * Obtener listado de anticipos con filtros.
     */
    public function get_anticipos(Request $request): JsonResponse
    {
        $filters = [
            'id_proveedor_minero' => $request->query('id_proveedor_minero'),
            'estado' => $request->query('estado'),
            'fecha_inicio' => $request->query('fecha_inicio'),
            'fecha_fin' => $request->query('fecha_fin'),
        ];

        $response = AnticiposProveedorService::get_anticipos($filters);

        return response()->json($response);
    }

    /**
     * Crear un nuevo anticipo de proveedor.
     */
    public function crear_anticipo(Request $request): JsonResponse
    {
        $request->validate([
            'id_proveedor_minero' => 'required|integer|exists:proveedor,id',
            'serie_factura' => 'nullable|string|max:10',
            'numero_factura' => 'nullable|string|max:20',
            'saldo_inicial' => 'required|numeric|min:0.01',
            'evidencias' => 'nullable|array',
            'evidencias.*' => 'file',
        ]);

        $authUser = $request->attributes->get('auth_user');

        $data = [
            'id_proveedor_minero' => (int) $request->input('id_proveedor_minero'),
            'id_empleado_registro' => (int) $authUser->id_empleado,
            'serie_factura' => $request->input('serie_factura'),
            'numero_factura' => $request->input('numero_factura'),
            'saldo_inicial' => (float) $request->input('saldo_inicial'),
        ];

        $archivos = $request->file('evidencias', []);
        if (! is_array($archivos)) {
            $archivos = [$archivos];
        }

        $response = AnticiposProveedorService::crear_anticipo($data, $archivos);

        return response()->json($response);
    }

    /**
     * Anular un anticipo de proveedor.
     */
    public function anular_anticipo(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'motivo' => 'required|string|min:3',
        ]);

        $authUser = $request->attributes->get('auth_user');

        $response = AnticiposProveedorService::anular_anticipo(
            $id,
            (string) $request->input('motivo'),
            (int) $authUser->id_empleado
        );

        return response()->json($response);
    }

    /**
     * Obtener transacciones asociadas a un anticipo.
     */
    public function get_transacciones(int $id): JsonResponse
    {
        $response = AnticiposProveedorService::get_transacciones($id);

        return response()->json($response);
    }

    /**
     * Obtener el historial de cambios unificado (cabecera + transacciones).
     */
    public function get_historial_cambios(int $id): JsonResponse
    {
        $response = AnticiposProveedorService::get_historial_combinado($id);

        return response()->json($response);
    }
}
