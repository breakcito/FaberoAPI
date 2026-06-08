<?php

use App\Modules\Marcas\Controllers\MarcasController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('marcas')->controller(MarcasController::class)->group(function () {
        Route::get('/', 'get_marcas');
        Route::post('/', 'crear_marca');
        Route::put('/{id}', 'editar_marca');
        Route::patch('/{id}/estado', 'cambiar_estado_marca');
    });
});
