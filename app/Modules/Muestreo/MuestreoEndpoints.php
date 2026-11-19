<?php

use App\Modules\Muestreo\Controllers\MuestreoController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('muestreo')->controller(MuestreoController::class)->group(function () {
        // Lotes pendientes de muestreo (con o sin particiones)
        Route::get('/lotes', 'get_lotes');
        Route::get('/lotes/{id}', 'get_lote');

        // Confirmar el muestreo de un lote (producto, tipo de mineral y correlativo)
        Route::post('/lotes/{id}/confirmar', 'confirmar_muestreo');
    });
});
