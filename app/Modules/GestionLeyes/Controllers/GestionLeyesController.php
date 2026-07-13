<?php

namespace App\Modules\GestionLeyes\Controllers;

use App\Modules\GestionLeyes\Services\GestionLeyesService;
use Illuminate\Http\Request;

class GestionLeyesController
{
    public function get_grupos()
    {
        return response()->json(GestionLeyesService::get_grupos());
    }

    public function crear_grupo(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:100',
            'orden' => 'nullable|integer',
            'indicar_origen' => 'required|boolean',
            'analitos' => 'present|array',
            'analitos.*.id_analito' => 'required|integer|exists:analito,id',
            'analitos.*.para_valorizacion_oro' => 'nullable|boolean',
            'analitos.*.para_valorizacion_plata' => 'nullable|boolean',
            'analitos.*.para_valorizacion_humedad' => 'nullable|boolean',
            'analitos.*.para_valorizacion_recuperacion' => 'nullable|boolean',
        ]);

        return response()->json(GestionLeyesService::crear_grupo(
            $request->nombre,
            $request->orden ?? 0,
            $request->indicar_origen,
            $request->analitos
        ));
    }

    public function editar_grupo(Request $request, int $id)
    {
        $request->validate([
            'nombre' => 'required|string|max:100',
            'orden' => 'nullable|integer',
            'indicar_origen' => 'required|boolean',
            'analitos' => 'present|array',
            'analitos.*.id_analito' => 'required|integer|exists:analito,id',
            'analitos.*.para_valorizacion_oro' => 'nullable|boolean',
            'analitos.*.para_valorizacion_plata' => 'nullable|boolean',
            'analitos.*.para_valorizacion_humedad' => 'nullable|boolean',
            'analitos.*.para_valorizacion_recuperacion' => 'nullable|boolean',
        ]);

        return response()->json(GestionLeyesService::editar_grupo(
            $id,
            $request->nombre,
            $request->orden ?? 0,
            $request->indicar_origen,
            $request->analitos
        ));
    }

    public function cambiar_estado_grupo(Request $request, int $id)
    {
        $request->validate([
            'estado' => 'required|string|in:Activo,Inactivo',
        ]);

        return response()->json(GestionLeyesService::cambiar_estado_grupo($id, $request->estado));
    }

    public function get_analitos()
    {
        return response()->json(GestionLeyesService::get_analitos());
    }

    public function crear_analito(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:100',
            'es_desplegable' => 'required|boolean',
        ]);

        return response()->json(GestionLeyesService::crear_analito(
            $request->nombre,
            $request->es_desplegable
        ));
    }

    public function cambiar_estado_analito(Request $request, int $id)
    {
        $request->validate([
            'estado' => 'required|string|in:Activo,Inactivo',
        ]);

        return response()->json(GestionLeyesService::cambiar_estado_analito($id, $request->estado));
    }

    public function editar_analito(Request $request, int $id)
    {
        $request->validate([
            'nombre' => 'required|string|max:100',
            'es_desplegable' => 'required|boolean',
        ]);

        return response()->json(GestionLeyesService::editar_analito(
            $id,
            $request->nombre,
            $request->es_desplegable
        ));
    }
}
