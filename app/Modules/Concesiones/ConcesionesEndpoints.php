<?php

use App\Modules\Concesiones\ConcesionesController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('concesiones')->controller(ConcesionesController::class)->group(function () {

        Route::get('/', 'get_concesiones');
        Route::post('/', 'crear_concesion');
        Route::get('/{id_concesion}', 'get_concesion');

        Route::prefix('contratos')->group(function () {
            Route::get('/empresas', 'get_empresas');
            Route::get('/{id_concesion}', 'get_contratos');
            Route::post('/', 'crear_contrato');
            Route::delete('/{id_contrato}', 'terminar_contrato');
        });
    });
});
