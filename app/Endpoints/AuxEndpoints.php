<?php

use App\Controllers\AuxController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('aux')->group(function () {
        Route::controller(AuxController::class)->group(function () {

            // empleados
            Route::get('/empleados', 'get_empleados');

            // proveedores
            Route::get('/proveedores', 'get_proveedores');

            // empresas
            Route::get('/empresas', 'get_empresas');

            // marcas
            Route::get('/marcas', 'get_marcas');
            Route::post('/marcas', 'crear_marca');

            // conductores
            Route::get('/conductores', 'get_conductores');
            Route::post('/conductores', 'crear_conductor');

            // ubigeo
            Route::get('/departamentos', 'get_departamentos');
            Route::get('/provincias', 'get_provincias');
            Route::get('/distritos', 'get_distritos');

            // tipos de vehiculo
            Route::get('/tipos-vehiculo', 'get_tipos_vehiculo');
            Route::post('/tipos-vehiculo', 'crear_tipo_vehiculo');
            Route::put('/tipos-vehiculo/{id}', 'editar_tipo_vehiculo');
            Route::patch('/tipos-vehiculo/{id}/estado', 'cambiar_estado_tipo_vehiculo');

            // empresas de transporte
            Route::get('/empresas-transporte', 'get_empresas_transporte');

            // vehiculos
            Route::get('/vehiculos', 'get_vehiculos');
            Route::post('/vehiculos', 'crear_vehiculo');
            Route::put('/vehiculos/{id}', 'editar_vehiculo');

            // motivos de ingreso
            Route::get('/motivos-ingreso', 'get_motivos_ingreso');

            // visitantes
            Route::get('/visitantes/buscar', 'buscar_visitante_por_dni');
            Route::post('/visitantes', 'crear_visitante');
        });
    });
});
