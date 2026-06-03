<?php

namespace App\Modules\Sucursales\Controller;

use App\Shared\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

class SucursalesController extends Controller
{
    /**
     * Listar todos los roles activos
     */
    public function get_sucursales(): JsonResponse
    {
        $result = SucursalesService::get_sucursales();
        return response()->json($result);
    }

    /**
     * Registrar una nueva sucursal con sus permisos
     */
    public function crear_sucursal(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_departamento' => 'nullable|integer',
            'id_provincia' => 'nullable|integer',
            'id_distrito' => 'nullable|integer',
            //
            'nombre' => 'required|string|max:256',
            'direccion' => 'nullable|string|max:512',
            'telefono' => 'nullable|string|max:64'
        ], [
            'id_departamento.integer' => 'El departamento debe ser un número entero.',
            'id_provincia.integer' => 'La provincia debe ser un número entero.',
            'id_distrito.integer' => 'El distrito debe ser un número entero.',
            //
            'nombre.required' => 'El nombre de la sucursal es obligatorio.',
            'nombre.string' => 'El nombre de la sucursal debe ser una cadena de texto.',
            'nombre.max' => 'El nombre de la sucursal no puede exceder los 256 caracteres.',
            //
            'direccion.string' => 'La dirección debe ser una cadena de texto.',
            'direccion.max' => 'La dirección no puede exceder los 512 caracteres.',
            //
            'telefono.string' => 'El teléfono debe ser una cadena de texto.',
            'telefono.max' => 'El teléfono no puede exceder los 64 caracteres.',
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()));
        }

        $result = RolesService::crear_rol($request->all());
        return response()->json($result);
    }

}
