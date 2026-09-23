<?php

namespace App\Modules\ProgramacionDespachos\Controllers;

use App\Modules\ProgramacionDespachos\Services\ActaSalidaVehiculoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class ActaSalidaVehiculoController extends Controller
{
    /**
     * GET /api/programacion-despachos/distribuciones/{id}/acta-salida
     */
    public function get_acta(int $id): JsonResponse
    {
        $status = (int) (ActaSalidaVehiculoService::get_por_distribucion($id)['success'] ?? false) === 1
            ? 200
            : 404;

        return response()->json(ActaSalidaVehiculoService::get_por_distribucion($id), $status);
    }
}
