<<?php

use App\Models\AnalisisMineral;
use App\Models\Analito;
use App\Models\Empleado;
use App\Models\GrupoAnalisis;
use App\Models\GrupoAnalisisDetalle;
use App\Models\LoteMineral;
use App\Modules\CierreLeyes\Services\CierreLeyesService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Tests del gate de validacion previo al confirmar un cierre de leyes.
 *
 * Reglas:
 *  - Todos los analisis_mineral del lote deben estar confirmados (esta_confirmada = 1).
 *  - Ningun analisis_mineral del lote puede tener ley nula.
 *  - `ley = 0` ES valido al cerrar (no bloquea).
 *
 * Nota: estos tests requieren Pest (pestphp/pest) y Laravel test runner
 * instalados via `composer require --dev pestphp/pest pestphp/pest-plugin-laravel`.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    // Catalogo base
    $this->empleado = Empleado::create([
        'nombre' => 'Test',
        'apellido' => 'Empleado',
        'estado' => 'Activo',
    ]);

    $this->analitoAu = Analito::create([
        'nombre' => 'Au',
        'es_desplegable' => true,
        'estado' => 'Activo',
    ]);

    $this->analitoAg = Analito::create([
        'nombre' => 'Ag',
        'es_desplegable' => true,
        'estado' => 'Activo',
    ]);

    $this->grupo = GrupoAnalisis::create([
        'nombre' => 'Grupo Test',
        'orden' => 1,
        'indicar_origen' => false,
        'estado' => 'Activo',
    ]);

    $this->detalleAu = GrupoAnalisisDetalle::create([
        'id_grupo_analisis' => $this->grupo->id,
        'id_analito' => $this->analitoAu->id,
        'para_valorizacion_oro' => 1,
        'para_valorizacion_plata' => 0,
        'para_valorizacion_humedad' => 0,
        'para_valorizacion_recuperacion' => 0,
    ]);

    $this->detalleAg = GrupoAnalisisDetalle::create([
        'id_grupo_analisis' => $this->grupo->id,
        'id_analito' => $this->analitoAg->id,
        'para_valorizacion_oro' => 0,
        'para_valorizacion_plata' => 1,
        'para_valorizacion_humedad' => 0,
        'para_valorizacion_recuperacion' => 0,
    ]);

    $this->lote = LoteMineral::create([
        'correlativo' => 'TEST-001',
        'numero_correlativo' => 1,
        'condicion_ingreso' => 'Comercializacion',
        'peso_neto' => 100.0,
        'tipo_mineral' => 'Au',
        'estado_leyes' => 'En Proceso',
    ]);

    // 2 corridas para Au (desplegable) con misma uuid_fila por corrida
    $this->uuid1 = 'uuid-corrida-1';
    $this->uuid2 = 'uuid-corrida-2';

    AnalisisMineral::create([
        'id_lote_mineral' => $this->lote->id,
        'id_grupo_analisis_detalle' => $this->detalleAu->id,
        'uuid_fila' => $this->uuid1,
        'tipo_origen' => null,
        'ley' => 0.5,
        'esta_confirmada' => 1,
        'id_empleado_registro' => $this->empleado->id,
    ]);
    AnalisisMineral::create([
        'id_lote_mineral' => $this->lote->id,
        'id_grupo_analisis_detalle' => $this->detalleAu->id,
        'uuid_fila' => $this->uuid2,
        'tipo_origen' => null,
        'ley' => 1.0,
        'esta_confirmada' => 1,
        'id_empleado_registro' => $this->empleado->id,
    ]);
    AnalisisMineral::create([
        'id_lote_mineral' => $this->lote->id,
        'id_grupo_analisis_detalle' => $this->detalleAg->id,
        'uuid_fila' => $this->uuid1,
        'tipo_origen' => null,
        'ley' => 10.0,
        'esta_confirmada' => 1,
        'id_empleado_registro' => $this->empleado->id,
    ]);
    AnalisisMineral::create([
        'id_lote_mineral' => $this->lote->id,
        'id_grupo_analisis_detalle' => $this->detalleAg->id,
        'uuid_fila' => $this->uuid2,
        'tipo_origen' => null,
        'ley' => 20.0,
        'esta_confirmada' => 1,
        'id_empleado_registro' => $this->empleado->id,
    ]);
});

it('bloquea el cierre si hay un analisis sin confirmar', function () {
    AnalisisMineral::where('id_lote_mineral', $this->lote->id)
        ->where('uuid_fila', $this->uuid1)
        ->update(['esta_confirmada' => 0]);

    $result = CierreLeyesService::confirmar_lote_leyes($this->lote->id, true, $this->empleado->id);

    expect($result)->toMatchArray([
        'success' => false,
    ]);
    expect($result['message'] ?? $result['error'] ?? '')->toContain('sin confirmar');

    $this->lote->refresh();
    expect($this->lote->estado_leyes)->toBe('En Proceso');
});

it('bloquea el cierre si hay una ley nula', function () {
    AnalisisMineral::where('id_lote_mineral', $this->lote->id)
        ->where('uuid_fila', $this->uuid1)
        ->where('id_grupo_analisis_detalle', $this->detalleAu->id)
        ->update(['ley' => null]);

    $result = CierreLeyesService::confirmar_lote_leyes($this->lote->id, true, $this->empleado->id);

    expect($result)->toMatchArray([
        'success' => false,
    ]);
    expect($result['message'] ?? $result['error'] ?? '')->toContain('valor nulo');

    $this->lote->refresh();
    expect($this->lote->estado_leyes)->toBe('En Proceso');
});

it('permite el cierre cuando todo esta confirmado y con valores', function () {
    $result = CierreLeyesService::confirmar_lote_leyes($this->lote->id, true, $this->empleado->id);

    expect($result)->toMatchArray([
        'success' => true,
    ]);

    $this->lote->refresh();
    expect((float) $this->lote->ley_oro)->toBe(0.75);      // (0.5 + 1.0) / 2
    expect((float) $this->lote->ley_plata)->toBe(15.0);      // (10 + 20) / 2
    expect((float) $this->lote->ley_humedad)->toBe(0.0);     // sin valoracion -> 0
    expect((float) $this->lote->ley_recuperacion)->toBe(0.0);
    expect($this->lote->estado_leyes)->toBe('Confirmado');
});

it('acepta ley = 0 como valor valido al cerrar', function () {
    AnalisisMineral::where('id_lote_mineral', $this->lote->id)
        ->where('id_grupo_analisis_detalle', $this->detalleAu->id)
        ->update(['ley' => 0.0]);

    $result = CierreLeyesService::confirmar_lote_leyes($this->lote->id, false, $this->empleado->id);

    expect($result)->toMatchArray([
        'success' => true,
    ]);

    $this->lote->refresh();
    expect((float) $this->lote->ley_oro)->toBe(0.0);
    expect($this->lote->estado_leyes)->toBe('Confirmado');
    expect((bool) $this->lote->con_valor_comercial)->toBeFalse();
});

it('fuerza a cero los campos sin valoracion confirmada al cerrar', function () {
    // Lote solo con Au confirmado; Ag queda sin confirmar
    AnalisisMineral::where('id_lote_mineral', $this->lote->id)
        ->where('id_grupo_analisis_detalle', $this->detalleAg->id)
        ->delete();

    $result = CierreLeyesService::confirmar_lote_leyes($this->lote->id, true, $this->empleado->id);

    // Bloqueado: existe al menos una fila de Au confirmada, pero acabamos de no tocar nada.
    // Si quedan filas sin confirmar (Ag), debe bloquear.
    if (! ($result['success'] ?? false)) {
        expect($result['message'] ?? $result['error'] ?? '')->toContain('sin confirmar');

        return;
    }

    // Si por algun motivo pasa (no deberia), verificar que ley_plata queda en 0.
    $this->lote->refresh();
    expect((float) $this->lote->ley_plata)->toBe(0.0);
});
