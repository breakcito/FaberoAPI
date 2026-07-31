<?php

use App\Modules\CondicionesComercialesProveedor\Controllers\CondicionesComercialesProveedorController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('proveedores/condiciones-comerciales')->controller(CondicionesComercialesProveedorController::class)->group(function () {
        Route::get('/', 'get_condiciones_por_proveedor');
        Route::post('/', 'crear_condicion');
        Route::put('/{id}', 'editar_condicion');
        Route::patch('/{id}/estado', 'cambiar_estado_condicion');
    });
});
