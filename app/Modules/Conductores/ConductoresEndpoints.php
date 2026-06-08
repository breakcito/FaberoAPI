<?php

use App\Modules\Conductores\Controllers\ConductoresController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('conductores')->controller(ConductoresController::class)->group(function () {
        Route::get('/', 'get_conductores');
        Route::post('/', 'crear_conductor');
        Route::put('/{id}', 'editar_conductor');
        Route::patch('/{id}/estado', 'cambiar_estado_conductor');
    });
});
