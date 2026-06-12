<?php

use App\Modules\RecepcionUnidades\Controllers\RecepcionUnidadesController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('recepcion-unidades')->controller(RecepcionUnidadesController::class)->group(function () {
        Route::get('/', 'get_recepciones');
        Route::post('/', 'crear_recepcion');
        Route::put('/{id}/salida', 'registrar_salida');
    });
});
