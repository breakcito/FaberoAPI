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
            Route::get('/{id}/contactos', 'get_contactos');
            Route::get('/{id}/contacto-documento', 'get_contacto_documento');
            Route::post('/contactos', 'crear_contacto');
            Route::put('/contactos/{id}', 'editar_contacto');
            Route::delete('/contactos/{id}', 'eliminar_contacto');
        });
    });
});
