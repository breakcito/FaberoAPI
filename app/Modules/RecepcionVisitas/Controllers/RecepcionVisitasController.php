<?php

namespace App\Modules\RecepcionVisitas\Controllers;

use App\Modules\RecepcionVisitas\Services\RecepcionVisitasService;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class RecepcionVisitasController extends Controller
{
    /**
     * Obtener listado de recepciones de visitas con filtros.
     */
    public function get_recepciones(Request $request): JsonResponse
    {
        $filters = [
            'fecha_inicio' => $request->query('fecha_inicio'),
            'fecha_fin' => $request->query('fecha_fin'),
        ];

        return response()->json(RecepcionVisitasService::get_recepciones($filters));
    }

    /**
     * Registrar una recepción de visita.
     */
    public function crear_recepcion(Request $request): JsonResponse
    {
        $request->validate([
            'id_empleado_contacto' => 'required|integer|exists:empleado,id',
            'id_motivo_ingreso' => 'required|integer|exists:motivo_ingreso,id',
            'observacion' => 'nullable|string',
            'con_vehiculo' => 'required|in:0,1,true,false',
            'serie_placa' => 'nullable|string|max:20',
            'numero_placa' => 'nullable|string|max:15',
            'visitantes' => 'required|array|min:1',
            'visitantes.*.id_visitante' => 'nullable|integer|exists:visitante,id',
            'visitantes.*.nombre' => 'nullable|string|max:100',
            'visitantes.*.apellido' => 'nullable|string|max:100',
            'visitantes.*.dni' => 'nullable|string|max:8',
            'visitantes.*.telefono' => 'nullable|string|max:50',
        ]);

        $authUser = $request->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado para registrar el ingreso.'), 401);
        }

        $conVehiculoInput = $request->input('con_vehiculo');
        $conVehiculo = ($conVehiculoInput === '1' || $conVehiculoInput === 1 || $conVehiculoInput === 'true' || $conVehiculoInput === true);

        $data = [
            'id_empleado_registro' => (int) $authUser->id_empleado,
            'id_empleado_contacto' => (int) $request->input('id_empleado_contacto'),
            'id_motivo_ingreso' => (int) $request->input('id_motivo_ingreso'),
            'observacion' => $request->input('observacion'),
            'con_vehiculo' => $conVehiculo,
            'serie_placa' => $conVehiculo ? $request->input('serie_placa') : null,
            'numero_placa' => $conVehiculo ? $request->input('numero_placa') : null,
        ];

        // Obtener archivos de los visitantes vinculados por su índice correspondiente
        $visitantes = $request->input('visitantes', []);
        $archivos = [];
        foreach ($visitantes as $index => $v) {
            $fileKey = "visitantes.{$index}.foto_documento";
            if ($request->hasFile($fileKey)) {
                $files = $request->file($fileKey);
                if (is_array($files)) {
                    $archivos[$index] = $files;
                } else {
                    $archivos[$index] = [$files];
                }
            }
        }

        return response()->json(RecepcionVisitasService::crear_recepcion($data, $visitantes, $archivos));
    }

    /**
     * Registrar la salida de una visita.
     */
    public function registrar_salida(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'observacion_salida' => 'nullable|string',
        ]);

        $result = RecepcionVisitasService::registrar_salida(
            $id,
            $request->input('observacion_salida')
        );

        return response()->json($result);
    }
}
