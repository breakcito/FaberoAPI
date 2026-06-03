<?php

namespace App\Modules\Empleados;

use App\Shared\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EmpleadosController
{
    /**
     * Listar empleados
     */
    public function get_empleados(Request $request): JsonResponse
    {
        $id_empresa = $request->query('id_empresa') ? (int) $request->query('id_empresa') : null;
        $result     = EmpleadosService::get_empleados($id_empresa);

        return response()->json($result);
    }

    /**
     * Obtener empresas activas
     */
    public function get_empresas(Request $request): JsonResponse
    {
        $result = EmpleadosService::get_empresas();

        return response()->json($result);
    }

    /**
     * Obtener áreas
     */
    public function get_areas(Request $request): JsonResponse
    {
        $result = EmpleadosService::get_areas();

        return response()->json($result);
    }

    /**
     * Obtener minas
     */
    public function get_minas(Request $request): JsonResponse
    {
        $result = EmpleadosService::get_minas();

        return response()->json($result);
    }

    /**
     * Obtener cargos por área
     */
    public function get_cargos(Request $request, int $id_area): JsonResponse
    {
        $result = EmpleadosService::get_cargos($id_area);

        return response()->json($result);
    }

    /**
     * Crear empleado
     */
    public function crear_empleado(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_empresa'         => 'required|integer',
            'id_cargo'           => 'required|integer',
            'nombre'             => 'required|string|max:255',
            'apellido'           => 'required|string|max:255',
            'dni'                => 'nullable|string|max:20',
            'ruc'                => 'nullable|string|max:20',
            'carnet_extranjeria' => 'nullable|string|max:20',
            'pasaporte'          => 'nullable|string|max:20',
            'fecha_nacimiento'   => 'nullable|date',
            'path_foto'          => 'nullable|image|mimes:jpg,png,jpeg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()));
        }

        $result = EmpleadosService::crear_empleado(
            id_empresa: (int) $request->input('id_empresa'),
            id_cargo: (int) $request->input('id_cargo'),
            nombre: (string) $request->input('nombre'),
            apellido: (string) $request->input('apellido'),
            dni: $request->input('dni'),
            ruc: $request->input('ruc'),
            carnet_extranjeria: $request->input('carnet_extranjeria'),
            pasaporte: $request->input('pasaporte'),
            fecha_nacimiento: $request->input('fecha_nacimiento'),
            foto: $request->file('path_foto')
        );

        return response()->json($result);
    }

    /**
     * Actualizar foto de empleado
     */
    public function actualizar_foto(Request $request, int $id_empleado): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'path_foto' => 'required|image|mimes:jpg,png,jpeg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()));
        }

        $result = EmpleadosService::actualizar_foto($id_empleado, $request->file('path_foto'));

        return response()->json($result);
    }
}
