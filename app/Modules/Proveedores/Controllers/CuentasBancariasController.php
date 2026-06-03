<?php

namespace App\Modules\Proveedores\Controllers;

use App\Modules\Proveedores\Services\CuentasBancariasService;
use Illuminate\Http\Request;

class CuentasBancariasController
{
    public function get_cuentas_bancarias(int $id_proveedor)
    {
        return response()->json(CuentasBancariasService::get_cuentas_bancarias($id_proveedor));
    }

    public function crear_cuenta_bancaria(Request $request)
    {
        $request->validate([
            'id_proveedor' => 'required|integer',
            'id_banco' => 'required|integer',
            'moneda' => 'required|string',
            'numero_cuenta' => 'required|string|max:50',
            'cci' => 'nullable|string|max:50',
            'es_para_detraccion' => 'required|boolean',
        ]);

        return response()->json(CuentasBancariasService::crear_cuenta_bancaria(
            (int) $request->id_proveedor,
            (int) $request->id_banco,
            $request->moneda,
            $request->numero_cuenta,
            $request->cci,
            (int) $request->es_para_detraccion
        ));
    }
}
