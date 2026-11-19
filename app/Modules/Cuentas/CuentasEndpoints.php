<?php

namespace App\Modules\Cuentas;

use Illuminate\Support\Facades\Route;

/**
 * Gestión de cuentas de usuario (crear, editar, permisos y sucursales).
 *
 * Requiere JWT: expone datos de usuarios y permite cambiar sus permisos.
 */
Route::middleware('auth.jwt.custom')->prefix('cuentas')->group(function () {
    Route::get('/', [CuentasController::class, 'get_cuentas']);
    Route::get('/empleados-disponibles', [CuentasController::class, 'get_empleados_sin_cuenta']);
    Route::get('/roles', [CuentasController::class, 'get_roles_disponibles']);
    Route::get('/permisos-catalogo', [CuentasController::class, 'get_catalogo_permisos']);
    Route::post('/', [CuentasController::class, 'crear_cuenta']);
    Route::put('/{id_usuario}', [CuentasController::class, 'actualizar_cuenta']);
    Route::post('/foto/{id_empleado}', [CuentasController::class, 'actualizar_foto_empleado']);
});
