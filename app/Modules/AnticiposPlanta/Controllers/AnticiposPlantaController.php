<?php

namespace App\Modules\AnticiposPlanta\Controllers;

use App\Modules\AnticiposPlanta\Services\AnticiposPlantaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnticiposPlantaController
{
    /**
     * Obtener listado de anticipos de planta con filtros.
     */
    public function get_anticipos(Request $request): JsonResponse
    {
        $filters = [
            'id_planta' => $request->query('id_planta'),
            'estado' => $request->query('estado'),
            'fecha_inicio' => $request->query('fecha_inicio'),
            'fecha_fin' => $request->query('fecha_fin'),
        ];

        $response = AnticiposPlantaService::get_anticipos($filters);

        return response()->json($response);
    }

    /**
     * Crear un nuevo anticipo de planta.
     */
    public function crear_anticipo(Request $request): JsonResponse
    {
        $request->validate([
            'id_planta' => 'required|integer|exists:planta_destino,id',
            'codigo_comprobante' => 'required|string|max:30',
            'saldo_inicial' => 'required|numeric|min:0.01',
            'evidencias' => 'nullable|array',
            'evidencias.*' => 'file',
        ]);

        $authUser = $request->attributes->get('auth_user');

        $data = [
            'id_planta' => (int) $request->input('id_planta'),
            'id_empleado_registro' => (int) $authUser->id_empleado,
            'codigo_comprobante' => $request->input('codigo_comprobante'),
            'saldo_inicial' => (float) $request->input('saldo_inicial'),
        ];

        $archivos = $request->file('evidencias', []);
        if (! is_array($archivos)) {
            $archivos = [$archivos];
        }

        $response = AnticiposPlantaService::crear_anticipo($data, $archivos);

        return response()->json($response);
    }

    /**
     * Anular un anticipo de planta.
     */
    public function anular_anticipo(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'motivo' => 'required|string|min:3',
        ]);

        $authUser = $request->attributes->get('auth_user');

        $response = AnticiposPlantaService::anular_anticipo(
            $id,
            (string) $request->input('motivo'),
            (int) $authUser->id_empleado
        );

        return response()->json($response);
    }

    /**
     * Obtener un anticipo de planta específico por ID.
     */
    public function get_anticipo_by_id(int $id): JsonResponse
    {
        $response = AnticiposPlantaService::get_anticipo_by_id($id);

        return response()->json($response);
    }
}
