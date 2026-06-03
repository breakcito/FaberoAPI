<?php

namespace App\Modules\Proveedores\Controllers;

use App\Modules\Proveedores\Services\BancosService;
use Illuminate\Http\Request;

class BancosController
{
    public function get_bancos()
    {
        return response()->json(BancosService::get_bancos());
    }

    public function crear_banco(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:100',
            'abreviatura' => 'required|string|max:20',
        ]);

        return response()->json(BancosService::crear_banco(
            $request->nombre,
            $request->abreviatura
        ));
    }
}
