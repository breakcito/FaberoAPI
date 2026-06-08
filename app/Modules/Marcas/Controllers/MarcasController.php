<?php

namespace App\Modules\Marcas\Controllers;

use App\Modules\Marcas\Services\MarcasService;
use Illuminate\Http\Request;

class MarcasController
{
    public function get_marcas()
    {
        return response()->json(MarcasService::get_marcas());
    }

    public function crear_marca(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:128',
        ]);

        return response()->json(MarcasService::crear_marca($request->nombre));
    }

    public function editar_marca(Request $request, int $id)
    {
        $request->validate([
            'nombre' => 'required|string|max:128',
        ]);

        return response()->json(MarcasService::editar_marca($id, $request->nombre));
    }

    public function cambiar_estado_marca(Request $request, int $id)
    {
        $request->validate([
            'estado' => 'required|string|in:Activo,Inactivo',
        ]);

        return response()->json(MarcasService::cambiar_estado_marca($id, $request->estado));
    }
}
