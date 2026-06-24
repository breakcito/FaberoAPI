<?php

namespace App\Modules\RecepcionUnidades\Controllers;

use App\Modules\RecepcionUnidades\Services\RecepcionUnidadesService;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class RecepcionUnidadesController extends Controller
{
    /**
     * Obtener listado de recepciones filtradas.
     */
    public function get_recepciones(Request $request): JsonResponse
    {
        $filters = [
            'fecha_inicio' => $request->query('fecha_inicio'),
            'fecha_fin' => $request->query('fecha_fin'),
            'numero_placa' => $request->query('numero_placa'),
            'serie_placa' => $request->query('serie_placa'),
            'id_empresa_transporte' => $request->query('id_empresa_transporte'),
            'tipo_ingreso' => $request->query('tipo_ingreso'),
        ];

        return response()->json(RecepcionUnidadesService::get_recepciones($filters));
    }

    /**
     * Registrar un nuevo ingreso/recepción de unidad.
     */
    public function crear_recepcion(Request $request): JsonResponse
    {
        $request->validate([
            'id_vehiculo' => 'required|integer|exists:vehiculo,id',
            'id_empresa_transporte' => 'required|integer|exists:empresa_transporte,id',
            'id_tipo_vehiculo' => 'required|integer|exists:tipo_vehiculo,id',
            'id_conductor' => 'required|integer|exists:conductor,id',
            'tipo_ingreso' => 'required|string|max:50',
            'tipo_carga' => 'required|string|max:50',
            'segunda_placa' => 'nullable|string|max:15',
            'observacion' => 'nullable|string',
            'evidencias' => 'nullable|array',
            'evidencias.*' => 'file',
            'id_sucursal' => 'required|integer|exists:sucursal,id',
        ]);

        $authUser = $request->attributes->get('auth_user');
        if (!$authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado para registrar el ingreso.'), 401);
        }

        $data = [
            'id_empleado_registro' => (int) $authUser->id_empleado,
            'id_vehiculo' => (int) $request->input('id_vehiculo'),
            'id_empresa_transporte' => (int) $request->input('id_empresa_transporte'),
            'id_tipo_vehiculo' => (int) $request->input('id_tipo_vehiculo'),
            'id_conductor' => (int) $request->input('id_conductor'),
            'tipo_ingreso' => $request->input('tipo_ingreso'),
            'tipo_carga' => $request->input('tipo_carga'),
            'segunda_placa' => $request->input('segunda_placa'),
            'observacion' => $request->input('observacion'),
            'id_surcusal' => (int) $request->input('id_sucursal'),
        ];

        // Obtener archivos subidos
        $archivos = [];
        if ($request->hasFile('evidencias')) {
            $archivos = $request->file('evidencias');
            if (!is_array($archivos)) {
                $archivos = [$archivos];
            }
        }

        return response()->json(RecepcionUnidadesService::crear_recepcion($data, $archivos));
    }

    /**
     * Registrar la salida de la unidad (estado y observación).
     */
    public function registrar_salida(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'estado_salida' => 'required|string|max:50',
            'observacion_salida' => 'nullable|string',
        ]);

        $result = RecepcionUnidadesService::registrar_salida(
            $id,
            $request->input('estado_salida'),
            $request->input('observacion_salida')
        );

        return response()->json($result);
    }
}
