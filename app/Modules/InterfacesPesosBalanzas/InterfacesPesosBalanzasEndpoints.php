<?php

use App\Modules\InterfacesPesosBalanzas\Controllers\InterfacesPesosBalanzasController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('interfaces-pesosbalanzas')
        ->controller(InterfacesPesosBalanzasController::class)
        ->group(function () {
            Route::get('/peso', 'get_peso');
            Route::post('/liberar', 'liberar');
        });
});
