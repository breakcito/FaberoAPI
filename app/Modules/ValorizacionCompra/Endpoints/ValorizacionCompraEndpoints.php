<?php

use App\Modules\ValorizacionCompra\Controllers\ValorizacionCompraController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('valorizacion-compra')->controller(ValorizacionCompraController::class)->group(function () {
        Route::get('/', 'listar_valorizaciones');
        Route::get('/{id}', 'obtener_valorizacion');
        Route::post('/', 'crear_valorizacion');
        Route::match(['put', 'post'], '/{id}', 'editar_valorizacion');
        Route::post('/{id}/aprobar', 'aprobar_valorizacion');
        Route::post('/{id}/anular', 'anular_valorizacion');
    });
});
