<?php

use App\Modules\Organigrama\OrganigramaController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('organigrama')->controller(OrganigramaController::class)->group(function () {

        Route::prefix('areas')->group(function () {
            Route::get('/', 'get_areas');
            Route::post('/', 'crear_area');
        });

        Route::prefix('cargos')->group(function () {
            Route::get('/{id_area}', 'get_cargos');
            Route::post('/', 'crear_cargo');
            Route::patch('/{id_cargo}/estado', 'cambiar_estado_cargo');
        });
    });
});
