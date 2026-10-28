<?php

use App\Modules\System\Controller\MenuSystemController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->prefix('system')->group(function () {

    Route::controller(MenuSystemController::class)->group(function () {
        // Arbol completo de menus -> submenus -> modulos
        Route::get('/menu-arbol', 'arbol');

        Route::post('/menu', 'crear_menu');
        Route::put('/menu/{id}', 'editar_menu');
        Route::delete('/menu/{id}', 'eliminar_menu');

        Route::post('/submenu', 'crear_submenu');
        Route::put('/submenu/{id}', 'editar_submenu');
        Route::delete('/submenu/{id}', 'eliminar_submenu');

        Route::post('/modulo', 'crear_modulo');
        Route::put('/modulo/{id}', 'editar_modulo');
        Route::delete('/modulo/{id}', 'eliminar_modulo');
    });
});
