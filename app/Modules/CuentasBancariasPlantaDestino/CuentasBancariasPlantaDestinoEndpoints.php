<?php

use App\Modules\CuentasBancariasPlantaDestino\Controllers\CuentasBancariasPlantaDestinoController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('plantas-destino/cuentas-bancarias')->controller(CuentasBancariasPlantaDestinoController::class)->group(function () {
        Route::get('/{id_planta}', 'get_cuentas_bancarias');
        Route::post('/', 'crear_cuenta_bancaria');
        Route::put('/{id}', 'editar_cuenta_bancaria');
        Route::patch('/{id}/estado', 'cambiar_estado_cuenta_bancaria');
    });
});
