<?php

namespace App\Modules\CondicionesComercialesProveedor\Controllers;

use App\Modules\CondicionesComercialesProveedor\Services\CondicionesComercialesProveedorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CondicionesComercialesProveedorController
{
    /**
     * Obtener condiciones comerciales por proveedor.
     */
    public function get_condiciones_por_proveedor(Request $request): JsonResponse
    {
        $idProveedor = $request->query('id_proveedor_minero') ?? $request->query('id_proveedor');
        if (! $idProveedor) {
            return response()->json(['success' => false, 'message' => 'El id_proveedor_minero es requerido.'], 422);
        }

        return response()->json(CondicionesComercialesProveedorService::get_condiciones_por_proveedor(
            (int) $idProveedor,
            $request->query('estado')
        ));
    }

    /**
     * Crear una nueva condición comercial.
     */
    public function crear_condicion(Request $request): JsonResponse
    {
        $request->validate([
            'id_proveedor_minero' => 'required_without:id_proveedor|nullable|integer|exists:proveedor,id',
            'id_proveedor' => 'required_without:id_proveedor_minero|nullable|integer|exists:proveedor,id',
            'elemento_quimico' => 'required|string|in:Oro,Plata',
            'ley_inicio' => 'required|numeric|min:0',
            'ley_fin' => 'required|numeric|gte:ley_inicio',
            'maquila' => 'required|numeric|min:0',
            'recuperacion' => 'required|numeric|min:0|max:100',
            'consumo' => 'required|numeric|min:0',
            'riesgo_comercial' => 'required|numeric|min:0',
        ]);

        $idProveedor = $request->input('id_proveedor_minero') ?? $request->input('id_proveedor');

        return response()->json(CondicionesComercialesProveedorService::crear_condicion(
            (int) $idProveedor,
            (string) $request->input('elemento_quimico'),
            (float) $request->input('ley_inicio'),
            (float) $request->input('ley_fin'),
            (float) $request->input('maquila'),
            (float) $request->input('recuperacion'),
            (float) $request->input('consumo'),
            (float) $request->input('riesgo_comercial')
        ));
    }

    /**
     * Editar una condición comercial.
     */
    public function editar_condicion(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'elemento_quimico' => 'required|string|in:Oro,Plata',
            'ley_inicio' => 'required|numeric|min:0',
            'ley_fin' => 'required|numeric|gte:ley_inicio',
            'maquila' => 'required|numeric|min:0',
            'recuperacion' => 'required|numeric|min:0|max:100',
            'consumo' => 'required|numeric|min:0',
            'riesgo_comercial' => 'required|numeric|min:0',
        ]);

        return response()->json(CondicionesComercialesProveedorService::editar_condicion(
            $id,
            (string) $request->input('elemento_quimico'),
            (float) $request->input('ley_inicio'),
            (float) $request->input('ley_fin'),
            (float) $request->input('maquila'),
            (float) $request->input('recuperacion'),
            (float) $request->input('consumo'),
            (float) $request->input('riesgo_comercial')
        ));
    }

    /**
     * Cambiar el estado de una condición comercial.
     */
    public function cambiar_estado_condicion(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'estado' => 'required|string|in:Activo,Inactivo',
        ]);

        return response()->json(CondicionesComercialesProveedorService::cambiar_estado_condicion(
            $id,
            (string) $request->input('estado')
        ));
    }
}
