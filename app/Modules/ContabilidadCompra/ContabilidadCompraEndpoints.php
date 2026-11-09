<?php

use App\Modules\ContabilidadCompra\Controllers\ComprobanteNotaController;
use App\Modules\ContabilidadCompra\Controllers\ContabilidadCompraController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('contabilidad-compra')->controller(ContabilidadCompraController::class)->group(function () {
        Route::get('/comprobantes', 'listar_comprobantes');
        Route::get('/comprobantes/{id}', 'obtener_comprobante');
        Route::post('/comprobantes', 'crear_comprobante');
        Route::post('/comprobantes/{id}/aprobar', 'aprobar_comprobante');
        Route::post('/comprobantes/{id}/anular', 'anular_comprobante');

        Route::get('/comprobantes/{id}/pagos', 'listar_pagos');
        Route::post('/comprobantes/{id}/pagos', 'registrar_pago');
        Route::post('/pagos/{id}/anular', 'anular_pago');
    });

    // Notas de crédito / débito sobre un comprobante de compra
    Route::prefix('contabilidad-compra')->controller(ComprobanteNotaController::class)->group(function () {
        Route::get('/comprobantes/{id}/notas', 'listar_notas');
        Route::post('/comprobantes/{id}/notas', 'registrar_nota');
        Route::post('/notas/{id}/anular', 'anular_nota');
    });
});
