<?php

use App\Modules\PlantasDestino\Controllers\PlantasDestinoController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('plantas-destino')->controller(PlantasDestinoController::class)->group(function () {
        // CRUD de Plantas
        Route::get('/', 'get_plantas');
        Route::post('/', 'crear_planta');
        Route::put('/{id}', 'editar_planta');
        Route::get('/{id}', 'get_planta');
        Route::patch('/{id}/estado', 'cambiar_estado_planta');

        // Asociación con Proveedores
        Route::get('/{id_planta}/proveedores', 'get_proveedores_asociados');
        Route::post('/proveedores', 'asociar_proveedor');
        Route::delete('/{id_planta}/proveedores/{id_proveedor}', 'desasociar_proveedor');
    });
});
