<?php

use App\Modules\Blending\Controllers\BlendingController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('blending')->controller(BlendingController::class)->group(function () {
        Route::get('/disponibles', 'disponibles');
        Route::get('/', 'listar');
        Route::get('/{id}', 'obtener');
        Route::post('/', 'crear');
        Route::match(['put', 'post'], '/{id}', 'editar');
        Route::post('/{id}/update', 'editar');
    });
});
