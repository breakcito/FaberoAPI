<?php

namespace App\Modules\TiposVehiculo\Controllers;

use App\Modules\TiposVehiculo\Services\TiposVehiculoService;
use Illuminate\Http\Request;

class TiposVehiculoController
{
    public function get_tipos_vehiculo()
    {
        return response()->json(TiposVehiculoService::get_tipos_vehiculo());
    }

    public function crear_tipo_vehiculo(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:100',
            'tiene_carreta' => 'nullable|boolean',
            'es_carreta' => 'nullable|boolean',
        ]);

        return response()->json(TiposVehiculoService::crear_tipo_vehiculo(
            $request->nombre,
            (bool) $request->input('tiene_carreta', false),
            (bool) $request->input('es_carreta', false)
        ));
    }

    public function editar_tipo_vehiculo(Request $request, int $id)
    {
        $request->validate([
            'nombre' => 'required|string|max:100',
            'tiene_carreta' => 'nullable|boolean',
            'es_carreta' => 'nullable|boolean',
        ]);

        return response()->json(TiposVehiculoService::editar_tipo_vehiculo(
            $id,
            $request->nombre,
            (bool) $request->input('tiene_carreta', false),
            (bool) $request->input('es_carreta', false)
        ));
    }

    public function cambiar_estado_tipo_vehiculo(Request $request, int $id)
    {
        $request->validate([
            'estado' => 'required|string|in:Activo,Inactivo',
        ]);

        return response()->json(TiposVehiculoService::cambiar_estado_tipo_vehiculo($id, $request->estado));
    }
}
