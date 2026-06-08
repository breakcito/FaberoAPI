<?php

namespace App\Modules\CuentasBancariasProveedor\Controllers;

use App\Modules\CuentasBancariasProveedor\Services\CuentasBancariasProveedorService;
use Illuminate\Http\Request;

class CuentasBancariasProveedorController
{
    public function get_cuentas_bancarias(int $id_proveedor)
    {
        return response()->json(CuentasBancariasProveedorService::get_cuentas_bancarias($id_proveedor));
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

        return response()->json(CuentasBancariasProveedorService::crear_cuenta_bancaria(
            (int) $request->id_proveedor,
            (int) $request->id_banco,
            $request->moneda,
            $request->numero_cuenta,
            $request->cci,
            (int) $request->es_para_detraccion
        ));
    }

    public function editar_cuenta_bancaria(Request $request, int $id)
    {
        $request->validate([
            'id_banco' => 'required|integer',
            'moneda' => 'required|string',
            'numero_cuenta' => 'required|string|max:50',
            'cci' => 'nullable|string|max:50',
            'es_para_detraccion' => 'required|boolean',
        ]);

        return response()->json(CuentasBancariasProveedorService::editar_cuenta_bancaria(
            $id,
            (int) $request->id_banco,
            $request->moneda,
            $request->numero_cuenta,
            $request->cci,
            (int) $request->es_para_detraccion
        ));
    }

    public function eliminar_cuenta_bancaria(Request $request, int $id)
    {
        return response()->json(CuentasBancariasProveedorService::eliminar_cuenta_bancaria($id));
    }

    public function cambiar_estado_cuenta_bancaria(Request $request, int $id)
    {
        $request->validate([
            'estado' => 'required|string|in:Activo,Inactivo',
        ]);

        return response()->json(CuentasBancariasProveedorService::cambiar_estado_cuenta_bancaria(
            $id,
            $request->estado
        ));
    }
}
