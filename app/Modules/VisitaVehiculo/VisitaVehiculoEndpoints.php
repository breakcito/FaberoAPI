<?php

use App\Modules\VisitaVehiculo\Controllers\VisitaVehiculoController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('visitas-vehiculo')->controller(VisitaVehiculoController::class)->group(function () {
        Route::get('/', 'get_visitas_vehiculo');
        Route::post('/', 'crear_visita_vehiculo');
        Route::delete('/{id}', 'eliminar_visita_vehiculo');
    });
});
