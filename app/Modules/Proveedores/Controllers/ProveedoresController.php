<?php

namespace App\Modules\Proveedores\Controllers;

use App\Modules\Proveedores\Services\ProveedoresService;
use Illuminate\Http\Request;

class ProveedoresController
{
    public function get_proveedores()
    {
        return response()->json(ProveedoresService::get_proveedores());
    }

    public function crear_proveedor(Request $request)
    {
        $request->validate([
            'tipo_entidad' => 'required|string',
            'dni' => 'nullable|string|size:8',
            'ruc' => 'nullable|string|size:11',
            'razon_social' => 'required|string|max:255',
            'direccion' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:20',
            'correo' => 'nullable|email|max:100',
        ]);

        return response()->json(ProveedoresService::crear_proveedor(
            $request->tipo_entidad,
            $request->dni,
            $request->ruc,
            $request->razon_social,
            $request->direccion,
            $request->telefono,
            $request->correo,
            $request->cuentas ?? []
        ));
    }

    public function editar_proveedor(Request $request, int $id)
    {
        $request->validate([
            'tipo_entidad' => 'required|string',
            'dni' => 'nullable|string|size:8',
            'ruc' => 'nullable|string|size:11',
            'razon_social' => 'required|string|max:255',
            'direccion' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:20',
            'correo' => 'nullable|email|max:100',
        ]);

        return response()->json(ProveedoresService::editar_proveedor(
            $id,
            $request->tipo_entidad,
            $request->dni,
            $request->ruc,
            $request->razon_social,
            $request->direccion,
            $request->telefono,
            $request->correo
        ));
    }

    public function cambiar_estado_proveedor(Request $request, int $id)
    {
        $request->validate([
            'estado' => 'required|string|in:Activo,Inactivo',
        ]);

        return response()->json(ProveedoresService::cambiar_estado_proveedor($id, $request->estado));
    }

    public function eliminar_proveedor(Request $request, int $id)
    {
        return response()->json(ProveedoresService::eliminar_proveedor($id));
    }

    public function get_concesiones(Request $request, int $id)
    {
        return response()->json(ProveedoresService::get_concesiones($id));
    }

    public function asociar_concesion(Request $request)
    {
        $request->validate([
            'id_proveedor' => 'required|integer',
            'id_concesion' => 'required|integer',
        ]);

        return response()->json(ProveedoresService::asociar_concesion(
            (int) $request->id_proveedor,
            (int) $request->id_concesion
        ));
    }

    public function desasociar_concesion(Request $request, int $id_proveedor, int $id_concesion)
    {
        return response()->json(ProveedoresService::desasociar_concesion(
            $id_proveedor,
            $id_concesion
        ));
    }
}
