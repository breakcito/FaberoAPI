<?php

namespace App\Modules\Conductores\Controllers;

use App\Modules\Conductores\Services\ConductoresService;
use Illuminate\Http\Request;

class ConductoresController
{
    public function get_conductores()
    {
        return response()->json(ConductoresService::get_conductores());
    }

    public function crear_conductor(Request $request)
    {
        $request->validate([
            'dni' => 'required|string|size:8|unique:conductor,dni',
            'ruc' => 'nullable|string|size:11|unique:conductor,ruc',
            'nombre' => 'required|string|max:100',
            'apellido' => 'required|string|max:100',
            'numero_licencia' => 'required|string|max:20',
        ]);

        return response()->json(ConductoresService::crear_conductor(
            $request->dni,
            $request->ruc,
            $request->nombre,
            $request->apellido,
            $request->numero_licencia
        ));
    }

    public function editar_conductor(Request $request, int $id)
    {
        $request->validate([
            'dni' => 'required|string|size:8|unique:conductor,dni,'.$id,
            'ruc' => 'nullable|string|size:11|unique:conductor,ruc,'.$id,
            'nombre' => 'required|string|max:100',
            'apellido' => 'required|string|max:100',
            'numero_licencia' => 'required|string|max:20',
        ]);

        return response()->json(ConductoresService::editar_conductor(
            $id,
            $request->dni,
            $request->ruc,
            $request->nombre,
            $request->apellido,
            $request->numero_licencia
        ));
    }

    public function cambiar_estado_conductor(Request $request, int $id)
    {
        $request->validate([
            'estado' => 'required|string|in:Activo,Inactivo',
        ]);

        return response()->json(ConductoresService::cambiar_estado_conductor($id, $request->estado));
    }
}
