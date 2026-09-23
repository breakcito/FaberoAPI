
<?php

use App\Modules\CondicionesComercialesPlanta\Controllers\CondicionesComercialesPlantaController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('plantas-destino/condiciones-comerciales')->controller(CondicionesComercialesPlantaController::class)->group(function () {
        Route::get('/', 'get_condiciones_por_planta');
        Route::post('/', 'crear_condicion');
        Route::put('/{id}', 'editar_condicion');
        Route::patch('/{id}/estado', 'cambiar_estado_condicion');
    });
});
