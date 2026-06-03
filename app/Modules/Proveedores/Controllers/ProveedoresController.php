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
}
