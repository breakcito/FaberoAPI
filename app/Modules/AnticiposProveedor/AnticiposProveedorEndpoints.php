<?php

use App\Modules\AnticiposProveedor\Controllers\AnticiposProveedorController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('anticipos-proveedor')->controller(AnticiposProveedorController::class)->group(function () {
        Route::get('/', 'get_anticipos');
        Route::post('/', 'crear_anticipo');
        Route::patch('/{id}/anular', 'anular_anticipo');
        Route::get('/{id}/transacciones', 'get_transacciones');
        Route::get('/{id}/historial-cambios', 'get_historial_cambios');
    });
});
