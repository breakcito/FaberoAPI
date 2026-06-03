<?php

namespace App\Modules\Roles;

use App\Shared\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

class RolesController extends Controller
{
    /**
     * Listar todos los roles activos
     */
    public function get_roles(): JsonResponse
    {
        $result = RolesService::get_roles();
        return response()->json($result);
    }

    /**
     * Obtener toda la estructura de Menús, Submenús y Módulos
     */
    public function get_estructura_permisos(): JsonResponse
    {
        $result = RolesService::get_estructura_permisos();
        return response()->json($result);
    }

    /**
     * Registrar un nuevo rol con sus permisos
     */
    public function crear_rol(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:64',
            'descripcion' => 'nullable|string|max:512',
            'modulos' => 'required|array|min:1',
            'modulos.*' => 'integer|exists:modulo,id'
        ], [
            'nombre.required' => 'El nombre del rol es obligatorio.',
            'modulos.required' => 'Debe seleccionar al menos un módulo.',
            'modulos.*.exists' => 'Uno de los módulos seleccionados no es válido.'
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()));
        }

        $result = RolesService::crear_rol($request->all());
        return response()->json($result);
    }

    /**
     * Obtener los IDs de los modulos asignados a un rol
     */
    public function get_permisos_rol(int $id_rol): JsonResponse
    {
        $result = RolesService::get_permisos_rol($id_rol);
        return response()->json($result);
    }

    /**
     * Actualizar los permisos (modulos) de un rol
     */
    public function actualizar_permisos_rol(Request $request, int $id_rol): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'modulos' => 'required|array|min:1',
            'modulos.*' => 'integer|exists:modulo,id'
        ], [
            'modulos.required' => 'Debe seleccionar al menos un módulo.',
            'modulos.*.exists' => 'Uno de los módulos seleccionados no es válido.'
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()));
        }

        $result = RolesService::actualizar_permisos_rol($id_rol, $request->input('modulos'));
        return response()->json($result);
    }
}
