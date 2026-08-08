<?php

namespace App\Modules\VisitaVehiculo\Controllers;

use App\Modules\VisitaVehiculo\Services\VisitaVehiculoService;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

class VisitaVehiculoController extends Controller
{
    /**
     * Listar vehículos acompañantes de una visita.
     */
    public function get_visitas_vehiculo(Request $request): JsonResponse
    {
        $id = (int) $request->query('id_recepcion_visita');
        if ($id <= 0) {
            return response()->json(ApiResponse::error('id_recepcion_visita es requerido'), 400);
        }

        return response()->json(VisitaVehiculoService::get_visitas_vehiculo($id));
    }

    /**
     * Registrar un vehículo acompañante (genera automáticamente N detalles de visita).
     */
    public function crear_visita_vehiculo(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_recepcion_visita' => 'required|integer',
            'placa' => 'required|string|max:15|regex:/^[A-Z]{3}-\d{3}$/',
            'cantidad_personas' => 'required|integer|min:1',
            'visitantes' => 'nullable|array',
            'visitantes.*.nombre' => 'nullable|string|max:100',
            'visitantes.*.apellido' => 'nullable|string|max:100',
            'visitantes.*.dni' => 'nullable|string|max:8',
            'visitantes.*.telefono' => 'nullable|string|max:50',
            'archivos' => 'nullable|array',
            'archivos.*' => 'file',
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()), 400);
        }

        $archivos = $request->file('archivos', []);
        if (! is_array($archivos)) {
            $archivos = [$archivos];
        }

        $result = VisitaVehiculoService::crear_visita_vehiculo(
            (int) $request->input('id_recepcion_visita'),
            (string) $request->input('placa'),
            (int) $request->input('cantidad_personas'),
            $request->input('visitantes', []),
            $archivos
        );

        return response()->json($result);
    }

    /**
     * Eliminar un vehículo acompañante (cascada a sus detalles).
     */
    public function eliminar_visita_vehiculo(int $id): JsonResponse
    {
        return response()->json(VisitaVehiculoService::eliminar_visita_vehiculo($id));
    }
}
