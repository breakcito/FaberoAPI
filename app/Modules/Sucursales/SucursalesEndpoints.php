<?php

use App\Modules\Sucursales\Controller\SucursalesController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('sucursales')->controller(SucursalesController::class)->group(function () {

        // Listar
        Route::get('/', 'get_sucursales');
        // Crear
        Route::post('/', 'crear_sucursal');

    });
});
