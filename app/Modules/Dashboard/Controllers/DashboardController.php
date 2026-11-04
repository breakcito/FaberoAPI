<?php

namespace App\Modules\Dashboard\Controllers;

use App\Modules\Dashboard\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController
{
    /**
     * Obtener el resumen de indicadores: evolución mensual de los últimos
     * 12 meses cerrados (con su comparativo interanual) y la fotografía
     * actual de planta y finanzas.
     */
    public function get_resumen(Request $request): JsonResponse
    {
        $request->validate([
            'id_sucursal' => 'nullable|integer|min:1',
        ]);

        $idSucursal = $request->query('id_sucursal') ? (int) $request->query('id_sucursal') : null;

        return response()->json(DashboardService::get_resumen($idSucursal));
    }
}
