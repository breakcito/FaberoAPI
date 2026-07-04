<?php

use App\Controllers\ConcesionesController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('concesiones')->controller(ConcesionesController::class)->group(function () {
        Route::get('/', 'get_concesiones');
        Route::post('/', 'crear_concesion');
        Route::put('/{id}', 'editar_concesion');
        Route::patch('/{id}/estado', 'cambiar_estado_concesion');
    });
});
