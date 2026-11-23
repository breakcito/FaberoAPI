<?php

namespace App\Modules\Dashboard\Controllers;

use App\Modules\Dashboard\Services\DashboardService;
use App\Shared\Enums\_Generic\CodigoPermiso;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController
{
    /**
     * Obtener el resumen de indicadores: evolución mensual de los últimos
     * 12 meses cerrados (con su comparativo interanual) y la fotografía
     * actual de planta y finanzas.
     *
     * FILTROS opcionales e independientes:
     *  - `id_proveedor_minero`: acota las TN recepcionadas.
     *  - `id_planta_destino`: acota las TN despachadas.
     */
    public function get_resumen(Request $request): JsonResponse
    {
        $request->validate([
            'id_sucursal' => 'nullable|integer|min:1',
            'id_proveedor_minero' => 'nullable|integer|min:1',
            'id_planta_destino' => 'nullable|integer|min:1',
        ]);

        // Permiso específico del usuario: `ver_dashboard`.
        $authUser = $request->attributes->get('auth_user');
        $permisos = is_object($authUser) && isset($authUser->permisos) && is_array($authUser->permisos)
            ? $authUser->permisos
            : [];

        if (! CodigoPermiso::permitido($permisos, CodigoPermiso::VerDashboard)) {
            return response()->json(
                ApiResponse::error('No tienes permiso para ver el Dashboard.'),
                403
            );
        }

        $idSucursal = $request->query('id_sucursal') ? (int) $request->query('id_sucursal') : null;
        $idProveedorMinero = $request->query('id_proveedor_minero') ? (int) $request->query('id_proveedor_minero') : null;
        $idPlantaDestino = $request->query('id_planta_destino') ? (int) $request->query('id_planta_destino') : null;

        return response()->json(
            DashboardService::get_resumen($idSucursal, $idProveedorMinero, $idPlantaDestino)
        );
    }
}
