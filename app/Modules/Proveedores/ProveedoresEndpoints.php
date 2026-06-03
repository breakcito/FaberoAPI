<?php

use App\Modules\Proveedores\Controllers\BancosController;
use App\Modules\Proveedores\Controllers\CuentasBancariasController;
use App\Modules\Proveedores\Controllers\ProveedoresController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('proveedores')->group(function () {
        Route::controller(ProveedoresController::class)->group(function () {
            Route::get('/', 'get_proveedores');
            Route::post('/', 'crear_proveedor');
        });

        Route::prefix('bancos')->controller(BancosController::class)->group(function () {
            Route::get('/', 'get_bancos');
            Route::post('/', 'crear_banco');
        });

        Route::prefix('cuentas-bancarias')->controller(CuentasBancariasController::class)->group(function () {
            Route::get('/{id_proveedor}', 'get_cuentas_bancarias');
            Route::post('/', 'crear_cuenta_bancaria');
        });
    });
});
