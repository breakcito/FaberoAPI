<?php

namespace App\Modules\Dashboard\Controllers;

use App\Modules\Dashboard\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController
{
    /**
     * Obtener el paquete completo de indicadores y métricas.
     */
    public function get_indicadores(Request $request): JsonResponse
    {
        $request->validate([
            'fecha_inicio' => 'nullable|date_format:Y-m-d',
            'fecha_fin' => 'nullable|date_format:Y-m-d',
            'id_sucursal' => 'nullable|integer|min:1',
        ]);

        $idSucursal = $request->query('id_sucursal') ? (int) $request->query('id_sucursal') : null;

        $result = DashboardService::get_indicadores(
            $idSucursal,
            $request->query('fecha_inicio'),
            $request->query('fecha_fin'),
        );

        return response()->json($result);
    }
}
