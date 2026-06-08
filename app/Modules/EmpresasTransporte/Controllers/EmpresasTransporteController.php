<?php

namespace App\Modules\EmpresasTransporte\Controllers;

use App\Modules\EmpresasTransporte\Services\EmpresasTransporteService;
use Illuminate\Http\Request;

class EmpresasTransporteController
{
    public function get_empresas_transporte()
    {
        return response()->json(EmpresasTransporteService::get_empresas_transporte());
    }

    public function crear_empresa_transporte(Request $request)
    {
        $request->validate([
            'tipo_entidad' => 'required|string|max:50',
            'dni' => 'nullable|string|size:8',
            'ruc' => 'required|string|size:11|unique:empresa_transporte,ruc',
            'razon_social' => 'required|string|max:255',
            'direccion' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:50',
            'correo' => 'nullable|email|max:100',
        ]);

        return response()->json(EmpresasTransporteService::crear_empresa_transporte(
            $request->tipo_entidad,
            $request->dni,
            $request->ruc,
            $request->razon_social,
            $request->direccion,
            $request->telefono,
            $request->correo
        ));
    }

    public function editar_empresa_transporte(Request $request, int $id)
    {
        $request->validate([
            'tipo_entidad' => 'required|string|max:50',
            'dni' => 'nullable|string|size:8',
            'ruc' => 'required|string|size:11|unique:empresa_transporte,ruc,'.$id,
            'razon_social' => 'required|string|max:255',
            'direccion' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:50',
            'correo' => 'nullable|email|max:100',
        ]);

        return response()->json(EmpresasTransporteService::editar_empresa_transporte(
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

    public function cambiar_estado_empresa_transporte(Request $request, int $id)
    {
        $request->validate([
            'estado' => 'required|string|in:Activo,Inactivo',
        ]);

        return response()->json(EmpresasTransporteService::cambiar_estado_empresa_transporte($id, $request->estado));
    }
}
