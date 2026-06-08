<?php

use App\Modules\Proveedores\Controllers\ProveedoresController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('proveedores')->group(function () {
        Route::controller(ProveedoresController::class)->group(function () {
            Route::get('/', 'get_proveedores');
            Route::post('/', 'crear_proveedor');
            Route::put('/{id}', 'editar_proveedor');
            Route::patch('/{id}/estado', 'cambiar_estado_proveedor');
            Route::delete('/{id}', 'eliminar_proveedor');
            Route::get('/{id}/concesiones', 'get_concesiones');
            Route::post('/concesiones', 'asociar_concesion');
            Route::delete('/{id_proveedor}/concesiones/{id_concesion}', 'desasociar_concesion');
        });
    });
});
