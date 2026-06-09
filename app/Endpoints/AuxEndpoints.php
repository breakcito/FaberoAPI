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
        });
    });
});
