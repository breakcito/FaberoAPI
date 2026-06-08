<?php

use App\Modules\TiposVehiculo\Controllers\TiposVehiculoController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('tipos-vehiculo')->controller(TiposVehiculoController::class)->group(function () {
        Route::get('/', 'get_tipos_vehiculo');
        Route::post('/', 'crear_tipo_vehiculo');
        Route::put('/{id}', 'editar_tipo_vehiculo');
        Route::patch('/{id}/estado', 'cambiar_estado_tipo_vehiculo');
    });
});
