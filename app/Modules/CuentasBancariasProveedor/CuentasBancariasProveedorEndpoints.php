<?php

use App\Modules\CuentasBancariasProveedor\Controllers\CuentasBancariasProveedorController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('proveedores/cuentas-bancarias')->controller(CuentasBancariasProveedorController::class)->group(function () {
        Route::get('/{id_proveedor}', 'get_cuentas_bancarias');
        Route::post('/', 'crear_cuenta_bancaria');
        Route::put('/{id}', 'editar_cuenta_bancaria');
        Route::patch('/{id}/estado', 'cambiar_estado_cuenta_bancaria');
        Route::delete('/{id}', 'eliminar_cuenta_bancaria');
    });
});
