<?php

namespace App\Modules\Vehiculos\Controllers;

use App\Modules\Vehiculos\Services\VehiculosService;
use Illuminate\Http\Request;

class VehiculosController
{
    public function get_vehiculos()
    {
        return response()->json(VehiculosService::get_vehiculos());
    }

    public function crear_vehiculo(Request $request)
    {
        $request->validate([
            'id_marca' => 'required|integer|exists:marca,id',
            'id_empresa_transporte' => 'required|integer|exists:empresa_transporte,id',
            'id_tipo_vehiculo' => 'required|integer|exists:tipo_vehiculo,id',
            'serie_placa' => 'nullable|string|max:20',
            'numero_placa' => 'required|string|max:15|unique:vehiculo,numero_placa',
            'numero_constancia_mtc' => 'nullable|string|max:50',
            'capacidad' => 'required|numeric|min:0',
            'tara' => 'required|numeric|min:0',
            'largo' => 'nullable|numeric|min:0',
            'ancho' => 'nullable|numeric|min:0',
            'alto' => 'nullable|numeric|min:0',
        ]);

        return response()->json(VehiculosService::crear_vehiculo(
            (int) $request->id_marca,
            (int) $request->id_empresa_transporte,
            (int) $request->id_tipo_vehiculo,
            $request->serie_placa,
            $request->numero_placa,
            $request->numero_constancia_mtc,
            (float) $request->capacidad,
            (float) $request->tara,
            $request->largo !== null ? (float) $request->largo : null,
            $request->ancho !== null ? (float) $request->ancho : null,
            $request->alto !== null ? (float) $request->alto : null
        ));
    }

    public function editar_vehiculo(Request $request, int $id)
    {
        $request->validate([
            'id_marca' => 'required|integer|exists:marca,id',
            'id_empresa_transporte' => 'required|integer|exists:empresa_transporte,id',
            'id_tipo_vehiculo' => 'required|integer|exists:tipo_vehiculo,id',
            'serie_placa' => 'nullable|string|max:20',
            'numero_placa' => 'required|string|max:15|unique:vehiculo,numero_placa,'.$id,
            'numero_constancia_mtc' => 'nullable|string|max:50',
            'capacidad' => 'required|numeric|min:0',
            'tara' => 'required|numeric|min:0',
            'largo' => 'nullable|numeric|min:0',
            'ancho' => 'nullable|numeric|min:0',
            'alto' => 'nullable|numeric|min:0',
        ]);

        return response()->json(VehiculosService::editar_vehiculo(
            $id,
            (int) $request->id_marca,
            (int) $request->id_empresa_transporte,
            (int) $request->id_tipo_vehiculo,
            $request->serie_placa,
            $request->numero_placa,
            $request->numero_constancia_mtc,
            (float) $request->capacidad,
            (float) $request->tara,
            $request->largo !== null ? (float) $request->largo : null,
            $request->ancho !== null ? (float) $request->ancho : null,
            $request->alto !== null ? (float) $request->alto : null
        ));
    }

    public function cambiar_estado_vehiculo(Request $request, int $id)
    {
        $request->validate([
            'estado' => 'required|string|in:Activo,Inactivo',
        ]);

        return response()->json(VehiculosService::cambiar_estado_vehiculo($id, $request->estado));
    }
}
