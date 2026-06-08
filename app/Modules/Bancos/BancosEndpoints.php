<?php

use App\Modules\Bancos\Controllers\BancosController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('proveedores/bancos')->controller(BancosController::class)->group(function () {
        Route::get('/', 'get_bancos');
        Route::post('/', 'crear_banco');
    });
});
