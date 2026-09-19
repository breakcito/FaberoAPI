<?php

use App\Modules\RecepcionMineral\Controllers\RecepcionMineralController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('recepcion-mineral')->controller(RecepcionMineralController::class)->group(function () {
        Route::get('/', 'get_recepciones_mineral');
        Route::get('/resumen', 'get_resumen_balanza');
        Route::get('/resumen/filtros', 'get_resumen_filtros');
        Route::get('/lotes-padre-particionados', 'get_lotes_padre_particionados');
        Route::put('/{id}/iniciar', 'iniciar_pesaje');
        Route::put('/{id}/validar', 'validar_campo');
        Route::post('/{id}/lotes', 'crear_lote');
        Route::delete('/lotes/{loteId}', 'eliminar_lote');
        Route::post('/lotes/{loteId}/peso-inicial', 'registrar_peso_inicial');
        Route::post('/lotes/{loteId}/peso-final', 'registrar_peso_final');
        Route::post('/lotes/{loteId}/actualizar', 'actualizar_lote');
        Route::get('/lotes/{loteId}/ticket-balanza', 'get_ticket_balanza');

        // ─── Particiones desde Balanza ───
        Route::post('/lotes/{idLote}/particiones', 'crear_particion');
        Route::get('/lotes/{idLote}/particiones', 'listar_particiones');
        Route::get('/particiones/{idParticion}', 'get_particion');
        Route::delete('/particiones/{idParticion}', 'eliminar_particion');
        Route::put('/particiones/{idParticion}/campos-no-peso', 'actualizar_campos_no_peso');
        Route::post('/particiones/{idParticion}/peso-inicial', 'registrar_peso_inicial_particion');
        Route::post('/particiones/{idParticion}/peso-final', 'registrar_peso_final_particion');
        Route::post('/lotes/{idLote}/particion/finalizar', 'finalizar_particion_lote');
        Route::get('/particiones/{idParticion}/ticket-balanza', 'get_ticket_balanza_particion');

        Route::get('/distribuciones-detalles/{idDistribucionDetalle}/ticket-balanza', 'get_ticket_balanza_by_distribucion_detalle');
        Route::put('/{id}/cerrar', 'cerrar_proceso');
    });
});
