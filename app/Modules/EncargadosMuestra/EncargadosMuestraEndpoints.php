<?php

use App\Modules\EncargadosMuestra\Controllers\EncargadosMuestraController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('encargados-muestra')->controller(EncargadosMuestraController::class)->group(function () {
        Route::get('/', 'get_encargados_muestra');
        Route::post('/', 'crear_encargado_muestra');
        Route::put('/{id}', 'editar_encargado_muestra');
        Route::patch('/{id}/estado', 'cambiar_estado_encargado_muestra');
    });
});
