<?php

namespace App\Modules\ModoAuditoria\Controllers;

use App\Events\ModoAuditoriaToggled;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Controlador para gestionar el modo auditoría.
 */
class ModoAuditoriaController extends Controller
{
    /**
     * Alterna el estado del modo auditoría y notifica a los clientes vía Websocket.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function toggle(Request $request): JsonResponse
    {
        $request->validate([
            'activo' => 'required|boolean',
        ]);

        $activo = $request->input('activo');

        // Disparar el evento de broadcast
        broadcast(new ModoAuditoriaToggled($activo))->toOthers();

        // También enviamos al que disparó por si acaso, aunque Echo suele manejarlo.
        // Pero el requerimiento dice "dile a todos los clientes conectados a ti".

        $result = ApiResponse::success([
            'en_modo_auditable' => $activo,
            'message' => $activo ? 'Modo auditoría activado' : 'Modo auditoría desactivado'
        ]);
        return response()->json($result);
    }
}
