<?php

use App\Modules\ModoAuditoria\Controllers\ModoAuditoriaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Módulo de Modo Auditoría (Acceso vía link oculto)
|--------------------------------------------------------------------------
*/

Route::prefix('modo-auditoria')->group(function () {
    Route::post('toggle', [ModoAuditoriaController::class, 'toggle']);
});
