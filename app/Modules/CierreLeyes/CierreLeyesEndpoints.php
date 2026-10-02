<?php

use App\Modules\CierreLeyes\Controllers\CierreLeyesController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('cierre-leyes')->controller(CierreLeyesController::class)->group(function () {
        Route::get('/lotes-sugeridos', 'get_lotes_sugeridos');
        Route::post('/lotes/iniciar', 'iniciar_lote');
        Route::get('/lotes', 'get_lotes_cierre');
        Route::post('/guardar-valor', 'guardar_valor_ley');
        Route::delete('/valores/{id}', 'eliminar_valor');
        Route::delete('/lotes/{id_lote_mineral}/filas/{uuid_fila}', 'eliminar_fila');
        Route::put('/lotes/{id_lote_mineral}/filas/{uuid_fila}/origen', 'actualizar_origen_fila');
        Route::post('/lotes/{id_lote_mineral}/analisis', 'agregar_analisis');
        Route::post('/lotes/confirmar', 'confirmar_lote_leyes');

        // Muestras externas (pre-análisis de un lote que aún no existe)
        Route::post('/muestras-externas/iniciar', 'iniciar_muestra_externa');
        Route::get('/muestras-externas', 'get_muestras_externas');
        Route::post('/muestras-externas/guardar-valor', 'guardar_valor_muestra_externa');
        Route::delete('/muestras-externas/{id_muestra_externa}/filas/{uuid_fila}', 'eliminar_fila_muestra_externa');
        Route::put('/muestras-externas/{id_muestra_externa}/filas/{uuid_fila}/origen', 'actualizar_origen_fila_muestra_externa');
        Route::post('/muestras-externas/{id_muestra_externa}/analisis', 'agregar_analisis_muestra');
        Route::post('/muestras-externas/{id_muestra_externa}/asociar', 'asociar_muestra_a_lote');
        Route::get('/muestras-externas/asociadas-por-lote', 'get_muestras_asociadas_por_lote');
    });
});
