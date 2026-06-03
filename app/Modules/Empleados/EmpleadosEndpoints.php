<?php

use App\Modules\Empleados\EmpleadosController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('empleados')->group(function () {

        // --- Proceso: Gestión general de empleados ---
        Route::controller(EmpleadosController::class)->group(function () {
            Route::get('/', 'get_empleados');
            Route::post('/', 'crear_empleado');
            Route::get('/empresas', 'get_empresas');
            Route::get('/areas', 'get_areas');
            Route::get('/minas', 'get_minas');
            Route::get('/cargos/{id_area}', 'get_cargos');
            Route::post('/foto/{id_empleado}', 'actualizar_foto');
        });
    });
});
