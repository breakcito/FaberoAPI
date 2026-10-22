<?php

use App\Modules\CierreLeyes\Controllers\CierreLeyesController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('cierre-leyes')->controller(CierreLeyesController::class)->group(function () {
        Route::get('/lotes-sugeridos', 'get_lotes_sugeridos');
        Route::get('/lotes', 'get_lotes_cierre');
        Route::post('/grupos', 'crear_grupo');
        Route::post('/grupos/{idGrupo}/analisis', 'agregar_analisis_grupo');
        Route::post('/grupos/{idGrupo}/leyes-cierre', 'guardar_leyes_cierre_grupo');
        Route::post('/analisis/{idAnalisis}/guardar', 'guardar_valores_analisis');
        Route::post('/analisis/{idAnalisis}/documentos', 'subir_documentos_analisis');
        Route::delete('/analisis/{idAnalisis}', 'eliminar_analisis');
        Route::post('/lotes/{idLote}/cerrar', 'confirmar_lote');

        // Muestras externas
        Route::get('/muestras-externas', 'get_muestras_externas');
        Route::post('/muestras-externas/iniciar', 'iniciar_muestra_externa');
        Route::post('/muestras-externas/{idMuestraExterna}/analisis', 'agregar_analisis_muestra_externa');
        Route::post('/muestras-externas/asociar-multiple', 'asociar_muestras_multiples_a_grupo');
        Route::post('/muestras-externas/{idMuestraExterna}/asociar', 'asociar_muestra_a_grupo');
        Route::get('/muestras-externas/asociadas-por-grupo', 'get_muestras_asociadas_por_grupo');
    });
});
