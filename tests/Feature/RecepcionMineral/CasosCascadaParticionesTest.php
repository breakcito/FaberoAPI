<?php

declare(strict_types=1);

namespace Tests\Feature\RecepcionMineral;

use PHPUnit\Framework\TestCase;

/**
 * Casos de uso documentados para la lógica de cascada no-peso en particiones.
 *
 * Ejecutar con: ./vendor/bin/pest (Pest) o ./vendor/bin/phpunit (PHPUnit compatible).
 * Requiere una BD de tests con seed mínimo: 1 proveedor, 1 zona_origen, 1 empleado
 * y 1 recepcion_unidad con su lote particionado desde Balanza.
 *
 * NOTA: tests funcionales con acceso real a BD. Si no hay BD disponible, estos
 * tests deben correr en un entorno con `RefreshDatabase` o equivalente. Se
 * recomienda integrarlos al CI del backend cuando esté listo el devcontainer.
 */
class CasosCascadaParticionesTest extends TestCase
{
    /**
     * Helper: crea un lote particionado desde Balanza con una partición A
     * (sin peso inicial) y devuelve [loteId, particionAId].
     */
    private function crearLoteConParticionA(): array
    {
        // Setup mínimo: insertar via DB directa para evitar acoplamiento a la API.
        $idRecepcionUnidad = (int) DB::table('recepcion_unidad')->insertGetId([
            'tipo_ingreso' => 'Recepción de Mineral',
            'estado_pesaje' => 'En Proceso',
            'estado' => 'Activo',
            'created_at' => now(),
        ]);

        $idEmpleado = (int) DB::table('empleado')->insertGetId([
            'nombre' => 'TEST', 'apellido' => 'OPERARIO', 'estado' => 'Activo',
        ]);

        $idLote = (int) DB::table('lote_mineral')->insertGetId([
            'id_recepcion_unidad' => $idRecepcionUnidad,
            'id_empleado_registro' => $idEmpleado,
            'correlativo' => 'TEST-' . uniqid(),
            'numero_correlativo' => 1,
            'con_codigo_manual' => false,
            'tiene_particion' => true,
            'particionado_desde_balanza' => true,
            'estado' => 'Activo',
            'estado_leyes' => 'Pendiente',
            'created_at' => now(),
        ]);

        $idParticionA = (int) DB::table('particion_lote_mineral')->insertGetId([
            'id_lote_mineral' => $idLote,
            'id_recepcion_unidad' => $idRecepcionUnidad,
            'correlativo' => 'TEST-' . uniqid() . '-A',
            'particion' => 'A',
            'estado' => 'Activo',
            'es_bloqueado' => false,
            'esta_validado' => false,
        ]);

        return [$idLote, $idParticionA];
    }

    /**
     * Helper: crea un proveedor y una zona_origen de prueba. Devuelve sus IDs.
     *
     * @return array{idProveedor:int,idZona:int}
     */
    private function crearProveedorYZona(): array
    {
        $idProveedor = (int) DB::table('proveedor')->insertGetId([
            'razon_social' => 'TEST_MINERA_X',
            'documento' => '20000000001',
            'telefono' => '987654321',
            'estado' => 'Activo',
        ]);
        $idZona = (int) DB::table('zona_origen')->insertGetId([
            'nombre' => 'TEST_ZONA_NORTE',
            'estado' => 'Activo',
        ]);

        return ['idProveedor' => $idProveedor, 'idZona' => $idZona];
    }

    /**
     * CASO 1: Pesar partición A con todos los campos no-peso completa el lote padre.
     */
    public function test_caso1_pesar_a_persiste_campos_no_peso_en_lote_padre(): void
    {
        [$idLote, $idParticionA] = $this->crearLoteConParticionA();
        ['idProveedor' => $idProv, 'idZona' => $idZona] = $this->crearProveedorYZona();

        $resultado = RecepcionMineralService::registrar_peso_inicial_particion(
            idParticion: $idParticionA,
            data: [
                'peso_inicial' => 100.5,
                'id_proveedor_minero' => $idProv,
                'id_zona_origen' => $idZona,
                'numero_contacto' => '987654321',
                'tipo_producto' => 'Aurífero',
                'tipo_mineral' => 'Óxido',
            ],
            archivos: [],
        );

        $this->assertTrue($resultado['success']);

        $lote = DB::table('lote_mineral')->where('id', $idLote)->first();
        $this->assertSame((int) $idProv, (int) $lote->id_proveedor_minero);
        $this->assertSame((int) $idZona, (int) $lote->id_zona_origen);
        $this->assertSame('987654321', $lote->numero_contacto);
        $this->assertSame('Aurífero', $lote->tipo_producto);
        $this->assertSame('Óxido', $lote->tipo_mineral);
    }

    /**
     * CASO 2: La partición B hereda los campos del lote padre vía JOIN.
     */
    public function test_caso2_particion_b_hereda_campos_del_padre(): void
    {
        [$idLote, $idParticionA] = $this->crearLoteConParticionA();
        ['idProveedor' => $idProv, 'idZona' => $idZona] = $this->crearProveedorYZona();

        // Paso 1: pesar A con todos los campos.
        RecepcionMineralService::registrar_peso_inicial_particion(
            idParticion: $idParticionA,
            data: [
                'peso_inicial' => 100.5,
                'id_proveedor_minero' => $idProv,
                'id_zona_origen' => $idZona,
                'numero_contacto' => '987654321',
                'tipo_producto' => 'Aurífero',
                'tipo_mineral' => 'Óxido',
            ],
            archivos: [],
        );

        // Paso 2: crear partición B en la misma unidad.
        $idParticionB = (int) DB::table('particion_lote_mineral')->insertGetId([
            'id_lote_mineral' => $idLote,
            'id_recepcion_unidad' => (int) DB::table('particion_lote_mineral')
                ->where('id', $idParticionA)->value('id_recepcion_unidad'),
            'correlativo' => 'TEST-B-' . uniqid(),
            'particion' => 'B',
            'estado' => 'Activo',
            'es_bloqueado' => false,
            'esta_validado' => false,
        ]);

        // Paso 3: GET de particiones debe traer los campos del padre.
        $particiones = RecepcionMineralData::get_particiones_by_lote($idLote);
        $b = collect($particiones)->firstWhere('id', $idParticionB);
        $this->assertNotNull($b);
        $this->assertSame($idProv, (int) $b->id_proveedor_minero);
        $this->assertSame($idZona, (int) $b->id_zona_origen);
        $this->assertSame('987654321', $b->numero_contacto);
        $this->assertSame('Aurífero', $b->tipo_producto);
        $this->assertSame('Óxido', $b->tipo_mineral);
    }

    /**
     * CASO 3: Editar solo zona en B mantiene el resto (no sobrescribe con null).
     */
    public function test_caso3_editar_solo_zona_no_sobrescribe_resto(): void
    {
        [$idLote, $idParticionA] = $this->crearLoteConParticionA();
        ['idProveedor' => $idProv, 'idZona' => $idZona] = $this->crearProveedorYZona();
        $idZonaSur = (int) DB::table('zona_origen')->insertGetId([
            'nombre' => 'TEST_ZONA_SUR', 'estado' => 'Activo',
        ]);

        RecepcionMineralService::registrar_peso_inicial_particion(
            idParticion: $idParticionA,
            data: [
                'peso_inicial' => 100.5,
                'id_proveedor_minero' => $idProv,
                'id_zona_origen' => $idZona,
                'numero_contacto' => '987654321',
                'tipo_producto' => 'Aurífero',
                'tipo_mineral' => 'Óxido',
            ],
            archivos: [],
        );

        // Crear partición B.
        $idParticionB = (int) DB::table('particion_lote_mineral')->insertGetId([
            'id_lote_mineral' => $idLote,
            'id_recepcion_unidad' => (int) DB::table('particion_lote_mineral')
                ->where('id', $idParticionA)->value('id_recepcion_unidad'),
            'correlativo' => 'TEST-B-' . uniqid(),
            'particion' => 'B',
            'estado' => 'Activo',
            'es_bloqueado' => false,
            'esta_validado' => false,
        ]);

        // Editar SOLO la zona en B.
        RecepcionMineralService::registrar_peso_inicial_particion(
            idParticion: $idParticionB,
            data: [
                'peso_inicial' => 80.5,
                'id_zona_origen' => $idZonaSur,
            ],
            archivos: [],
        );

        // El lote debe tener la zona actualizada, pero el resto intacto.
        $lote = DB::table('lote_mineral')->where('id', $idLote)->first();
        $this->assertSame($idZonaSur, (int) $lote->id_zona_origen);
        $this->assertSame($idProv, (int) $lote->id_proveedor_minero);
        $this->assertSame('987654321', $lote->numero_contacto);
        $this->assertSame('Aurífero', $lote->tipo_producto);
        $this->assertSame('Óxido', $lote->tipo_mineral);
    }

    /**
     * CASO 4: Eliminar físicamente la última partición → lote padre a Eliminado.
     */
    public function test_caso4_eliminar_ultima_particion_marca_lote_como_eliminado(): void
    {
        [$idLote, $idParticionA] = $this->crearLoteConParticionA();

        $resultado = RecepcionMineralService::eliminar_particion($idParticionA, idEmpleado: null);
        $this->assertTrue($resultado['success']);

        $lote = DB::table('lote_mineral')->where('id', $idLote)->first();
        $this->assertSame('Eliminado', $lote->estado);
        $this->assertSame(0, (int) $lote->tiene_particion);
        $this->assertDatabaseMissing('particion_lote_mineral', ['id' => $idParticionA]);
    }

    /**
     * CASO 5: Eliminar con idEmpleado registra log de cambios con snapshot completo.
     */
    public function test_caso5_eliminar_particion_genera_log_de_cambios(): void
    {
        [$idLote, $idParticionA] = $this->crearLoteConParticionA();
        ['idProveedor' => $idProv, 'idZona' => $idZona] = $this->crearProveedorYZona();

        // Pesar A primero.
        RecepcionMineralService::registrar_peso_inicial_particion(
            idParticion: $idParticionA,
            data: [
                'peso_inicial' => 100.5,
                'id_proveedor_minero' => $idProv,
            ],
            archivos: [],
        );

        $idEmpleado = (int) DB::table('empleado')->insertGetId([
            'nombre' => 'TEST', 'apellido' => 'AUDITOR', 'estado' => 'Activo',
        ]);

        RecepcionMineralService::eliminar_particion($idParticionA, $idEmpleado);

        $lote = DB::table('lote_mineral')->where('id', $idLote)->first();
        $log = json_decode($lote->log_cambios ?? '[]', true);
        $this->assertNotEmpty($log);

        $entrada = $log[0];
        $this->assertSame((int) $idEmpleado, (int) $entrada['id_empleado']);
        $this->assertSame('Eliminación física de partición', $entrada['motivo']);
        $this->assertNotEmpty($entrada['cambios']);

        $campos = collect($entrada['cambios'])->pluck('campo_bd')->all();
        $this->assertContains('particion_lote_mineral.id_particion', $campos);
        $this->assertContains('particion_lote_mineral.correlativo', $campos);
        $this->assertContains('particion_lote_mineral.peso_inicial', $campos);
    }

    /**
     * CASO 6: Eliminar una partición pero quedan otras → lote NO se marca Eliminado.
     */
    public function test_caso6_quedan_particiones_lote_se_mantiene_activo(): void
    {
        [$idLote, $idParticionA] = $this->crearLoteConParticionA();
        $idParticionB = (int) DB::table('particion_lote_mineral')->insertGetId([
            'id_lote_mineral' => $idLote,
            'id_recepcion_unidad' => (int) DB::table('particion_lote_mineral')
                ->where('id', $idParticionA)->value('id_recepcion_unidad'),
            'correlativo' => 'TEST-B-' . uniqid(),
            'particion' => 'B',
            'estado' => 'Activo',
            'es_bloqueado' => false,
            'esta_validado' => false,
        ]);

        RecepcionMineralService::eliminar_particion($idParticionA, null);

        $lote = DB::table('lote_mineral')->where('id', $idLote)->first();
        // Queda B activa → lote NO debe estar Eliminado.
        $this->assertNotSame('Eliminado', $lote->estado);
        $this->assertSame(1, (int) $lote->tiene_particion);
        $this->assertDatabaseMissing('particion_lote_mineral', ['id' => $idParticionA]);
        $this->assertDatabaseHas('particion_lote_mineral', ['id' => $idParticionB]);
    }

    /**
     * CASO 7: Pesos finales también propagan campos no-peso al lote padre.
     */
    public function test_caso7_peso_final_tambien_propagar_campos_no_peso(): void
    {
        [$idLote, $idParticionA] = $this->crearLoteConParticionA();
        ['idProveedor' => $idProv, 'idZona' => $idZona] = $this->crearProveedorYZona();

        // Pesar A (peso inicial).
        RecepcionMineralService::registrar_peso_inicial_particion(
            idParticion: $idParticionA,
            data: ['peso_inicial' => 100.0],
            archivos: [],
        );

        // Pesar A (peso final) con campos no-peso nuevos.
        $resultado = RecepcionMineralService::registrar_peso_final_particion(
            idParticion: $idParticionA,
            data: [
                'peso_final' => 20.0,
                'tipo_producto' => 'Polimetálico',
                'tipo_mineral' => 'Sulfuro',
            ],
        );
        $this->assertTrue($resultado['success']);

        $lote = DB::table('lote_mineral')->where('id', $idLote)->first();
        $this->assertSame('Polimetálico', $lote->tipo_producto);
        $this->assertSame('Sulfuro', $lote->tipo_mineral);
    }
}
