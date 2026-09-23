<?php

use App\Modules\ProgramacionDespachos\Controllers\ActaSalidaVehiculoController;
use App\Modules\ProgramacionDespachos\Controllers\GuiaSegundoTramoController;
use App\Modules\ProgramacionDespachos\Controllers\ProgramacionDespachosController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('programacion-despachos')->controller(ProgramacionDespachosController::class)->group(function () {
        Route::get('/items-disponibles', 'get_items_disponibles');

        Route::get('/', 'get_despachos');
        Route::post('/', 'crear_despacho');
        Route::get('/{id}', 'get_despacho');
        Route::patch('/{id}/anular', 'anular_despacho');

        Route::post('/{id}/distribuciones', 'crear_distribucion');
        Route::patch('/distribuciones/{id}/confirmar', 'confirmar_distribucion');
        Route::patch('/distribuciones/{id}/salida', 'registrar_salida');
        Route::patch('/distribuciones/{id}/llegada', 'registrar_llegada');
        Route::put('/distribuciones/{id}/datos-cliente', 'actualizar_datos_cliente');
        Route::get('/distribuciones/{id}/lotes-disponibles', 'get_lotes_disponibles_para_distribucion');
        Route::post('/distribuciones/{id}/detalles', 'agregar_detalle_distribucion');
        Route::post('/distribuciones/{id}/detalles/{idDetalle}/pesar', 'pesar_distribucion_detalle');
    });

    // Guia Segundo Tramo (una por distribucion).
    // Las rutas se declaran ANTES del wildcard generico {id} para que Laravel
    // matchee correctamente las URLs prefijadas con "distribuciones".
    Route::prefix('programacion-despachos')->controller(GuiaSegundoTramoController::class)->group(function () {
        Route::get('/distribuciones/{id}/guia-segundo-tramo', 'get_guia');
        Route::post('/distribuciones/{id}/guia-segundo-tramo', 'crear_guia');
        Route::post('/distribuciones/{id}/guia-segundo-tramo/{idGuia}/update', 'actualizar_guia');
        Route::patch('/distribuciones/{id}/guia-segundo-tramo/{idGuia}/anular', 'anular_guia');
    });

    // Acta de Salida de Vehículos con Carga (ticket A5 horizontal).
    Route::prefix('programacion-despachos')->controller(ActaSalidaVehiculoController::class)->group(function () {
        Route::get('/distribuciones/{id}/acta-salida', 'get_acta');
    });
});
