<?php

use App\Controllers\AuxController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('aux')->group(function () {
        // empleados
        Route::get('/empleados', [AuxController::class, 'get_empleados']);

        // proveedores
        Route::get('/proveedores', [AuxController::class, 'get_proveedores']);

        // empresas
        Route::get('/empresas', [AuxController::class, 'get_empresas']);

        // marcas
        Route::get('/marcas', [AuxController::class, 'get_marcas']);
        Route::post('/marcas', [AuxController::class, 'crear_marca']);

        // conductores
        Route::get('/conductores', [AuxController::class, 'get_conductores']);
        Route::post('/conductores', [AuxController::class, 'crear_conductor']);

        // ubigeo
        Route::get('/departamentos', [AuxController::class, 'get_departamentos']);
        Route::get('/provincias', [AuxController::class, 'get_provincias']);
        Route::get('/distritos', [AuxController::class, 'get_distritos']);

        // tipos de vehiculo
        Route::get('/tipos-vehiculo', [AuxController::class, 'get_tipos_vehiculo']);
        Route::post('/tipos-vehiculo', [AuxController::class, 'crear_tipo_vehiculo']);
        Route::put('/tipos-vehiculo/{id}', [AuxController::class, 'editar_tipo_vehiculo']);
        Route::patch('/tipos-vehiculo/{id}/estado', [AuxController::class, 'cambiar_estado_tipo_vehiculo']);

        // empresas de transporte
        Route::get('/empresas-transporte', [AuxController::class, 'get_empresas_transporte']);

        // vehiculos
        Route::get('/vehiculos', [AuxController::class, 'get_vehiculos']);
        Route::post('/vehiculos', [AuxController::class, 'crear_vehiculo']);
        Route::put('/vehiculos/{id}', [AuxController::class, 'editar_vehiculo']);

        // motivos de ingreso
        Route::get('/motivos-ingreso', [AuxController::class, 'get_motivos_ingreso']);

        // visitantes
        Route::get('/visitantes/buscar', [AuxController::class, 'buscar_visitante_por_dni']);
        Route::post('/visitantes', [AuxController::class, 'crear_visitante']);

        // sucursales
        Route::get('/sucursales', [AuxController::class, 'get_sucursales']);

        // zonas de origen
        Route::get('/zonas-origen', [AuxController::class, 'get_zonas_origen']);
        Route::post('/zonas-origen', [AuxController::class, 'crear_zona_origen']);

        // encargados de muestra
        Route::get('/encargados-muestra', [AuxController::class, 'get_encargados_muestra']);
    });
});

