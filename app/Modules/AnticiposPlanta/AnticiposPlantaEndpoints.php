
<?php

use App\Modules\AnticiposPlanta\Controllers\AnticiposPlantaController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('anticipos-planta')->controller(AnticiposPlantaController::class)->group(function () {
        Route::get('/', 'get_anticipos');
        Route::get('/{id}', 'get_anticipo_by_id');
        Route::post('/', 'crear_anticipo');
        Route::patch('/{id}/anular', 'anular_anticipo');
    });
});
