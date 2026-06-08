<?php

use App\Modules\Vehiculos\Controllers\VehiculosController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('vehiculos')->controller(VehiculosController::class)->group(function () {
        Route::get('/', 'get_vehiculos');
        Route::post('/', 'crear_vehiculo');
        Route::put('/{id}', 'editar_vehiculo');
        Route::patch('/{id}/estado', 'cambiar_estado_vehiculo');
    });
});
