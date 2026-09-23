<?php

namespace App\Modules\CondicionesComercialesPlanta\Controllers;

use App\Modules\CondicionesComercialesPlanta\Services\CondicionesComercialesPlantaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CondicionesComercialesPlantaController
{
    /**
     * Obtener condiciones comerciales por planta destino.
     */
    public function get_condiciones_por_planta(Request $request): JsonResponse
    {
        $idPlanta = $request->query('id_planta');
        if (! $idPlanta) {
            return response()->json(['success' => false, 'message' => 'El id_planta es requerido.'], 422);
        }

        return response()->json(CondicionesComercialesPlantaService::get_condiciones_por_planta(
            (int) $idPlanta,
            $request->query('estado')
        ));
    }

    /**
     * Crear una nueva condición comercial de planta destino.
     */
    public function crear_condicion(Request $request): JsonResponse
    {
        $request->validate([
            'id_planta' => 'required|integer|exists:planta_destino,id',
            'elemento_quimico' => 'required|string|in:Oro,Plata',
            'ley_inicio' => 'nullable|numeric|min:0',
            'ley_fin' => 'nullable|numeric|min:0',
            'maquila' => 'nullable|numeric|min:0',
            'recuperacion' => 'nullable|numeric|min:0|max:100',
            'consumo' => 'nullable|numeric|min:0',
            'riesgo_comercial' => 'nullable|numeric|min:0',
        ]);

        return response()->json(CondicionesComercialesPlantaService::crear_condicion(
            (int) $request->input('id_planta'),
            (string) $request->input('elemento_quimico'),
            $request->input('ley_inicio') !== null && $request->input('ley_inicio') !== ''
                ? (float) $request->input('ley_inicio') : null,
            $request->input('ley_fin') !== null && $request->input('ley_fin') !== ''
                ? (float) $request->input('ley_fin') : null,
            $request->input('maquila') !== null && $request->input('maquila') !== ''
                ? (float) $request->input('maquila') : null,
            $request->input('recuperacion') !== null && $request->input('recuperacion') !== ''
                ? (float) $request->input('recuperacion') : null,
            $request->input('consumo') !== null && $request->input('consumo') !== ''
                ? (float) $request->input('consumo') : null,
            $request->input('riesgo_comercial') !== null && $request->input('riesgo_comercial') !== ''
                ? (float) $request->input('riesgo_comercial') : null
        ));
    }

    /**
     * Editar una condición comercial de planta destino.
     */
    public function editar_condicion(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'elemento_quimico' => 'required|string|in:Oro,Plata',
            'ley_inicio' => 'nullable|numeric|min:0',
            'ley_fin' => 'nullable|numeric|min:0',
            'maquila' => 'nullable|numeric|min:0',
            'recuperacion' => 'nullable|numeric|min:0|max:100',
            'consumo' => 'nullable|numeric|min:0',
            'riesgo_comercial' => 'nullable|numeric|min:0',
        ]);

        return response()->json(CondicionesComercialesPlantaService::editar_condicion(
            $id,
            (string) $request->input('elemento_quimico'),
            $request->input('ley_inicio') !== null && $request->input('ley_inicio') !== ''
                ? (float) $request->input('ley_inicio') : null,
            $request->input('ley_fin') !== null && $request->input('ley_fin') !== ''
                ? (float) $request->input('ley_fin') : null,
            $request->input('maquila') !== null && $request->input('maquila') !== ''
                ? (float) $request->input('maquila') : null,
            $request->input('recuperacion') !== null && $request->input('recuperacion') !== ''
                ? (float) $request->input('recuperacion') : null,
            $request->input('consumo') !== null && $request->input('consumo') !== ''
                ? (float) $request->input('consumo') : null,
            $request->input('riesgo_comercial') !== null && $request->input('riesgo_comercial') !== ''
                ? (float) $request->input('riesgo_comercial') : null
        ));
    }

    /**
     * Cambiar el estado de una condición comercial de planta destino.
     */
    public function cambiar_estado_condicion(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'estado' => 'required|string|in:Activo,Inactivo',
        ]);

        return response()->json(CondicionesComercialesPlantaService::cambiar_estado_condicion(
            $id,
            (string) $request->input('estado')
        ));
    }
}
