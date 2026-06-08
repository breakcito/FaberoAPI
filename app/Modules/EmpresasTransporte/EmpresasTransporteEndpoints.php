<?php

use App\Modules\EmpresasTransporte\Controllers\EmpresasTransporteController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('empresas-transporte')->controller(EmpresasTransporteController::class)->group(function () {
        Route::get('/', 'get_empresas_transporte');
        Route::post('/', 'crear_empresa_transporte');
        Route::put('/{id}', 'editar_empresa_transporte');
        Route::patch('/{id}/estado', 'cambiar_estado_empresa_transporte');
    });
});
