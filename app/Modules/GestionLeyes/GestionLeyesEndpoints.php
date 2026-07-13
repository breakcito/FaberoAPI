<?php

use App\Modules\GestionLeyes\Controllers\GestionLeyesController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('gestion-leyes')->controller(GestionLeyesController::class)->group(function () {
        Route::get('/grupos', 'get_grupos');
        Route::post('/grupos', 'crear_grupo');
        Route::put('/grupos/{id}', 'editar_grupo');
        Route::patch('/grupos/{id}/estado', 'cambiar_estado_grupo');

        Route::get('/analitos', 'get_analitos');
        Route::post('/analitos', 'crear_analito');
        Route::put('/analitos/{id}', 'editar_analito');
        Route::patch('/analitos/{id}/estado', 'cambiar_estado_analito');
    });
});
