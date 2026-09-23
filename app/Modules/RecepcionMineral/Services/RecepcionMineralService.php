<?php

namespace App\Modules\RecepcionMineral\Services;

use App\Models\Empresa;
use App\Models\LoteMineral;
use App\Models\ParticionLoteMineral;
use App\Models\RecepcionUnidad;
use App\Models\Vehiculo;
use App\Modules\RecepcionMineral\Data\RecepcionMineralData;
use App\Shared\Enums\_Generic\CondicionIngreso;
use App\Shared\Enums\_Generic\EstadoBase;
use App\Shared\Enums\_Generic\EstadoLeyes;
use App\Shared\Enums\_Generic\Periodo;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Helpers\CorrelativoHelper;
use App\Shared\Responses\ApiResponse;
use App\Shared\Responses\_Generic\RES_CambiosLog;
use Illuminate\Support\Facades\DB;

class RecepcionMineralService
{
    /**
     * Obtener listado de recepciones filtradas por sucursal
     */
    public static function get_recepciones_mineral(array $filters): array
    {
        if (empty($filters['id_sucursal'])) {
            return ApiResponse::error('Debe seleccionar una sucursal.');
        }

        $data = RecepcionMineralData::get_recepciones_mineral($filters);

        return ApiResponse::success($data, 'Recepciones obtenidas correctamente');
    }

    /**
     * Iniciar el proceso de pesaje
     */
    public static function iniciar_pesaje(int $id): array
    {
        $recepcion = RecepcionUnidad::find($id);
        if (! $recepcion) {
            return ApiResponse::error('No se encontró el registro de recepción.');
        }

        $recepcion->estado_pesaje = 'En Proceso';
        $recepcion->fecha_hora_inicio_pesaje = now()->toDateTimeString();
        $recepcion->save();

        $updated = RecepcionMineralData::get_recepcion_by_id_with_lotes($id);

        return ApiResponse::success($updated, 'Proceso de pesaje iniciado correctamente.');
    }

    /**
     * Validar y actualizar un campo del registro de recepción o vehículo de forma dinámica
     */
    public static function validar_campo(int $id, string $field, $value): array
    {
        $recepcion = RecepcionUnidad::find($id);
        if (! $recepcion) {
            return ApiResponse::error('No se encontró el registro de recepción.');
        }

        // 1. Modificar la tabla correspondiente según el campo
        switch ($field) {
            case 'condicion_ingreso':
                $recepcion->tipo_ingreso = $value;
                break;

            case 'placa':
                $vehiculo = null;
                if ($recepcion->id_vehiculo) {
                    $vehiculo = Vehiculo::find($recepcion->id_vehiculo);
                }

                // Separar serie y número si viene en formato XXX-YYY
                $serie = null;
                $numero = $value ? strtoupper(trim($value)) : '';
                if ($value && str_contains($value, '-')) {
                    $parts = explode('-', $value, 2);
                    $serie = strtoupper(trim($parts[0]));
                    $numero = strtoupper(trim($parts[1]));
                }

                // Buscar si existe un vehículo con esa placa en la BD
                $vehiculoExistente = Vehiculo::where('placa', $value)->first();

                if ($vehiculoExistente) {
                    // Si ya existe el vehículo, asociamos su ID
                    $recepcion->id_vehiculo = $vehiculoExistente->id;
                    $recepcion->save();

                    // Si el vehículo que tenía asignado anteriormente era ficticio o temporal, lo borramos para no dejar basura
                    if ($vehiculo && $vehiculo->id !== $vehiculoExistente->id) {
                        if ($vehiculo->placa && str_contains($vehiculo->placa, 'FICT')) {
                            $vehiculo->delete();
                        }
                    }
                } else {
                    // Si no existe, creamos o modificamos el vehículo actual
                    if (! $vehiculo) {
                        $vehiculo = Vehiculo::create([
                            'estado' => 'Activo',
                        ]);
                        $recepcion->id_vehiculo = $vehiculo->id;
                    }
                    $vehiculo->placa = $value;
                    $vehiculo->save();

                    $recepcion->save();
                }
                break;

            case 'empresa_transporte':
                $recepcion->id_empresa_transporte = $value ? (int) $value : null;
                break;

            case 'tipo_vehiculo':
                $recepcion->id_tipo_vehiculo = $value ? (int) $value : null;
                if ($recepcion->id_vehiculo) {
                    $vehiculo = Vehiculo::find($recepcion->id_vehiculo);
                    if ($vehiculo) {
                        $vehiculo->id_tipo_vehiculo = $value ? (int) $value : null;
                        $vehiculo->save();
                    }
                }
                break;

            case 'id_vehiculo_carreta':
                $recepcion->id_vehiculo_carreta = $value ? (int) $value : null;
                break;

            case 'conductor':
                $recepcion->id_conductor = $value ? (int) $value : null;
                break;

            case 'fecha_hora_ingreso':
                if ($value) {
                    $parsed = date('Y-m-d H:i:s', strtotime($value));
                    if ($parsed) {
                        $recepcion->fecha_hora_ingreso = $parsed;
                    }
                }
                break;
        }

        $recepcion->save();

        $updated = RecepcionMineralData::get_recepcion_by_id_with_lotes($id);

        return ApiResponse::success($updated, 'Campo validado y actualizado correctamente.');
    }

    /**
     * Crear un lote vacío para una recepción de unidad.
     *
     * Si $particionar=true:
     *   - Crea lote SIN id_recepcion_unidad, SIN ticket, con particionado_desde_balanza=1.
     *   - Crea automáticamente la partición A con id_recepcion_unidad=$id (la unidad actual),
     *     SIN ticket (se crea al primer pesaje), correlativo = lote.correlativo.'-A', etc.
     *   - Todo en una sola transacción.
     *
     * @param  int  $id  id de la recepción de unidad
     */
    public static function crear_lote(
        int $id,
        int $idEmpleadoRegistro,
        string $condicionIngreso,
        int $idEmpresa,
        bool $conCodigoManual = false,
        ?string $codigoManual = null,
        bool $particionar = false,
    ): array {
        $recepcion = RecepcionUnidad::find($id);
        if (! $recepcion) {
            return ApiResponse::error('No se encontró el registro de recepción.');
        }

        $empresa = Empresa::find($idEmpresa);
        if (! $empresa) {
            return ApiResponse::error('No se encontró la empresa seleccionada.');
        }

        // Generar correlativo y número: manual o automático
        if ($conCodigoManual) {
            $correlativo = $codigoManual;
            $numeroCorrelativo = null;
        } else {
            $isComercial = $condicionIngreso === CondicionIngreso::Comercializacion->value;
            $prefijo = $isComercial ? 'FB' : 'LOT';
            $filtros = $isComercial
                ? ['id_empresa' => $idEmpresa, 'condicion_ingreso' => CondicionIngreso::Comercializacion->value]
                : ['condicion_ingreso' => ['!=', CondicionIngreso::Comercializacion->value]];

            // Generar correlativo usando CorrelativoHelper
            $correlativoData = CorrelativoHelper::generar(
                tabla: 'lote_mineral',
                prefijo: $prefijo,
                filtros: $filtros,
                longitudCeros: 5,
                reseteo: Periodo::Anual
            );
            $correlativo = $correlativoData['correlativo'];
            $numeroCorrelativo = $correlativoData['numero_correlativo'];
        }

        // Particionar desde balanza: lote sin unidad, sin ticket; partición A con la unidad actual.
        if ($particionar) {
            DB::beginTransaction();
            try {
                $lote = LoteMineral::create([
                    'id_recepcion_unidad' => null,
                    'id_empleado_registro' => $idEmpleadoRegistro,
                    'id_empresa' => $idEmpresa,
                    'condicion_ingreso' => $condicionIngreso,
                    'correlativo' => $correlativo,
                    'numero_correlativo' => $numeroCorrelativo,
                    'con_codigo_manual' => $conCodigoManual,
                    'id_ticket_balanza' => null,
                    'tiene_particion' => true,
                    'particionado_desde_balanza' => true,
                    'particion_finalizada' => false,
                    'estado_leyes' => EstadoLeyes::Pendiente->value,
                    'estado' => EstadoBase::Activo->value,
                    'created_at' => now()->toDateTimeString(),
                ]);

                DB::table('particion_lote_mineral')->insert([
                    'id_lote_mineral' => $lote->id,
                    'id_ticket_balanza' => null,
                    'id_recepcion_unidad' => $id,
                    'correlativo' => $correlativo.'-A',
                    'particion' => 'A',
                    'peso_inicial' => null,
                    'fecha_hora_peso_inicial' => null,
                    'peso_final' => null,
                    'fecha_hora_peso_final' => null,
                    'peso_neto' => null,
                    'estado' => EstadoBase::Activo->value,
                    'es_bloqueado' => false,
                    'esta_validado' => true,
                    'id_empleado_valida' => $idEmpleadoRegistro,
                    'fecha_hora_validacion' => now()->toDateTimeString(),
                    'evidencias' => null,
                ]);

                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();

                return ApiResponse::error('Error al crear el lote particionado: '.$e->getMessage(), 500);
            }

            $loteDetalle = RecepcionMineralData::get_lote_by_id($lote->id);

            return ApiResponse::success($loteDetalle, 'Lote particionado generado correctamente.');
        }

        // Flujo normal: crear ticket de balanza al generar el lote.
        $correlativoTicketData = CorrelativoHelper::generar(
            tabla: 'ticket_balanza',
            prefijo: '',
            filtros: [],
            longitudCeros: 0,
            reseteo: Periodo::Diario,
            formatoFecha: 'dmy',
            incluirPrefijo: false,
        );

        $ticketId = DB::table('ticket_balanza')->insertGetId([
            'correlativo' => $correlativoTicketData['correlativo'],
            'numero_correlativo' => $correlativoTicketData['numero_correlativo'],
            'created_at' => now(),
        ]);

        $lote = LoteMineral::create([
            'id_recepcion_unidad' => $id,
            'id_empleado_registro' => $idEmpleadoRegistro,
            'id_empresa' => $idEmpresa,
            'condicion_ingreso' => $condicionIngreso,
            'correlativo' => $correlativo,
            'numero_correlativo' => $numeroCorrelativo,
            'con_codigo_manual' => $conCodigoManual,
            'id_ticket_balanza' => $ticketId,
            'estado_leyes' => EstadoLeyes::Pendiente->value,
            'estado' => EstadoBase::Activo->value,
            'created_at' => now()->toDateTimeString(),
        ]);

        $loteDetalle = RecepcionMineralData::get_lote_by_id($lote->id);

        return ApiResponse::success($loteDetalle, 'Lote generado correctamente.');
    }

    /**
     * Eliminar un lote vacío o incompleto (Eliminación Lógica)
     */
    public static function eliminar_lote(int $loteId): array
    {
        $lote = LoteMineral::find($loteId);
        if (! $lote) {
            return ApiResponse::error('No se encontró el registro de lote.');
        }

        $lote->estado = EstadoBase::Eliminado;
        $lote->save();

        return ApiResponse::success(null, 'Lote eliminado correctamente.');
    }

    /**
     * Registrar la información del peso inicial de un lote.
     */
    public static function registrar_peso_inicial(int $loteId, array $data, array $archivos): array
    {
        $lote = LoteMineral::find($loteId);
        if (! $lote) {
            return ApiResponse::error('No se encontró el registro de lote.');
        }

        $pesoInicialInput = array_key_exists('peso_inicial', $data) && $data['peso_inicial'] !== null
            ? (float) $data['peso_inicial']
            : null;

        if ($pesoInicialInput === null || $pesoInicialInput <= 0) {
            return ApiResponse::error('Debe registrar un peso inicial válido y mayor a cero.', 422);
        }

        // Guardar los archivos de evidencias físicas en storage/app/public/lotes
        $evidenciasGuardadas = [];
        if (! empty($archivos)) {
            $evidenciasGuardadas = ArchivoHelper::guardarArchivos('lotes', $archivos);
        }

        $lote->id_proveedor_minero = $data['id_proveedor_minero'] ? (int) $data['id_proveedor_minero'] : null;
        $lote->id_zona_origen = $data['id_zona_origen'] ? (int) $data['id_zona_origen'] : null;
        $lote->numero_contacto = $data['numero_contacto'];
        $lote->tipo_producto = $data['tipo_producto'];
        $lote->tipo_mineral = $data['tipo_mineral'];
        $lote->peso_inicial = $pesoInicialInput;
        $lote->fecha_hora_peso_inicial = now()->toDateTimeString();
        $lote->evidencias = $evidenciasGuardadas;

        // Al registrar el peso inicial desde balanza, crear registro en ticket_balanza si no tiene uno
        if (! $lote->id_ticket_balanza) {
            $correlativoTicketData = CorrelativoHelper::generar(
                tabla: 'ticket_balanza',
                prefijo: '',
                filtros: [],
                longitudCeros: 0,
                reseteo: Periodo::Diario,
                formatoFecha: 'dmy',
                incluirPrefijo: false,
            );

            $ticketId = DB::table('ticket_balanza')->insertGetId([
                'correlativo' => $correlativoTicketData['correlativo'],
                'numero_correlativo' => $correlativoTicketData['numero_correlativo'],
                'created_at' => now(),
            ]);
            $lote->id_ticket_balanza = $ticketId;
        }

        $lote->save();

        $updatedLote = RecepcionMineralData::get_lote_by_id($loteId);

        return ApiResponse::success($updatedLote, 'Peso inicial registrado correctamente.');
    }

    /**
     * Obtener los datos completos del ticket de balanza
     */
    public static function get_ticket_balanza(int $loteId): array
    {
        $data = RecepcionMineralData::get_ticket_balanza_info($loteId);
        if (! $data) {
            return ApiResponse::error('No se encontró la información del ticket para el lote especificado.');
        }

        return ApiResponse::success($data, 'Ticket de balanza obtenido correctamente.');
    }

    /**
     * Obtener los datos completos del ticket de balanza partiendo de un id de
     * distribucion_detalle. Se usa para imprimir el ticket de las filas del Bloque B
     * (Despacho de Mineral) del Resumen de Balanza, incluyendo despachos cuyo origen
     * es un blending.
     */
    public static function get_ticket_balanza_by_distribucion_detalle(int $idDistribucionDetalle): array
    {
        $data = RecepcionMineralData::get_ticket_balanza_info_by_distribucion_detalle($idDistribucionDetalle);
        if (! $data) {
            return ApiResponse::error('No se encontró la información del ticket para el detalle de distribución especificado.');
        }

        return ApiResponse::success($data, 'Ticket de balanza obtenido correctamente.');
    }

    /**
     * Registrar la información del peso final de un lote.
     */
    public static function registrar_peso_final(int $loteId, array $data, array $archivos): array
    {
        $lote = LoteMineral::find($loteId);
        if (! $lote) {
            return ApiResponse::error('No se encontró el registro de lote.');
        }

        $pesoFinalInput = array_key_exists('peso_final', $data) && $data['peso_final'] !== null
            ? (float) $data['peso_final']
            : null;

        if ($pesoFinalInput === null || $pesoFinalInput <= 0) {
            return ApiResponse::error('Debe registrar un peso final válido y mayor a cero.', 422);
        }

        if ($lote->peso_inicial === null && ! isset($data['peso_inicial']) && $pesoFinalInput !== null) {
            return ApiResponse::error('Debe registrar primero el peso inicial del lote.');
        }

        // Guardar los archivos de evidencias físicas y anexarlos a los existentes
        $evidenciasGuardadas = [];
        if (array_key_exists('evidencias_existentes', $data)) {
            $evidenciasGuardadas = is_array($data['evidencias_existentes'])
                ? $data['evidencias_existentes']
                : (json_decode($data['evidencias_existentes'], true) ?? []);
        } else {
            $evidenciasGuardadas = $lote->evidencias ?? [];
        }

        if (! empty($archivos)) {
            $nuevosArchivos = ArchivoHelper::guardarArchivos('lotes', $archivos);
            $evidenciasGuardadas = array_merge($evidenciasGuardadas, $nuevosArchivos);
        }

        // Actualizar datos del peso inicial si fueron provistos
        if (array_key_exists('id_proveedor_minero', $data)) {
            $lote->id_proveedor_minero = $data['id_proveedor_minero'] ? (int) $data['id_proveedor_minero'] : null;
        }
        if (array_key_exists('id_zona_origen', $data)) {
            $lote->id_zona_origen = $data['id_zona_origen'] ? (int) $data['id_zona_origen'] : null;
        }
        if (array_key_exists('numero_contacto', $data)) {
            $lote->numero_contacto = $data['numero_contacto'];
        }
        if (array_key_exists('tipo_producto', $data)) {
            $lote->tipo_producto = $data['tipo_producto'];
        }
        if (array_key_exists('tipo_mineral', $data)) {
            $lote->tipo_mineral = $data['tipo_mineral'];
        }
        if (array_key_exists('peso_inicial', $data) && $data['peso_inicial'] !== null) {
            $lote->peso_inicial = (float) $data['peso_inicial'];
        }

        $pesoFinal = $pesoFinalInput;
        $pesoInicial = (float) $lote->peso_inicial;

        $lote->peso_final = $pesoFinal;
        $lote->fecha_hora_peso_final = now()->toDateTimeString();
        $lote->peso_neto = $pesoInicial - $pesoFinal; // Peso Inicial - Peso Final
        $lote->peso_actual = $lote->peso_neto; // Mantener peso_actual sincronizado con peso_neto
        $lote->evidencias = $evidenciasGuardadas;
        $lote->save();

        $updatedLote = RecepcionMineralData::get_lote_by_id($loteId);

        return ApiResponse::success($updatedLote, 'Peso final registrado correctamente.');
    }

    /**
     * Cerrar el proceso de balanza de una recepción.
     *
     * Bifurca la validación según `tipo_ingreso`:
     *   - "Recepción de Mineral" → exige `lote_mineral` con `peso_final` registrado.
     *   - "Despacho de Mineral" → exige `distribucion_detalle` con `peso_neto` registrado.
     *
     * En ambos casos sella `recepcion_unidad.estado_pesaje = 'Pesado'` y
     * `fecha_hora_final_pesaje`, lo que automáticamente saca la unidad de la
     * lista de Balanza (filtro `estado_pesaje IN ('Sin Pesar', 'En Proceso')`).
     */
    public static function cerrar_proceso(int $id): array
    {
        $recepcion = RecepcionUnidad::find($id);
        if (! $recepcion) {
            return ApiResponse::error('No se encontró el registro de recepción.');
        }

        $tipoIngreso = $recepcion->tipo_ingreso;

        if ($tipoIngreso === 'Despacho de Mineral') {
            $idDistribucion = DB::table('recepcion_unidad')
                ->where('id', $id)
                ->value('id_distribucion');

            if (! $idDistribucion) {
                return ApiResponse::error('La unidad de despacho no tiene una distribución asociada.');
            }

            $detalles = DB::table('distribucion_detalle')
                ->where('id_distribucion', $idDistribucion)
                ->get();

            if ($detalles->isEmpty()) {
                return ApiResponse::error('Debe registrar al menos un detalle de distribución con peso.');
            }

            foreach ($detalles as $detalle) {
                if ($detalle->peso_neto === null || ! $detalle->peso_tara_confirmado || ! $detalle->peso_bruto_confirmado) {
                    return ApiResponse::error('Todos los detalles de distribución deben tener la tara y el bruto confirmados.');
                }
            }
        } else {
            // Recepción de Mineral: la unidad puede tener lotes regulares y/o
            // particiones de lotes padre particionados desde Balanza (cuyo
            // padre tiene `id_recepcion_unidad = NULL` y por tanto no aparece
            // en la consulta de lotes regulares). Si AMBAS listas están
            // vacías, no hay nada que cerrar.
            $lotes = LoteMineral::where('id_recepcion_unidad', $id)->get();
            $particionesDeUnidad = DB::table('particion_lote_mineral')
                ->where('id_recepcion_unidad', $id)
                ->where('estado', EstadoBase::Activo->value)
                ->get();
            if ($lotes->isEmpty() && $particionesDeUnidad->isEmpty()) {
                return ApiResponse::error('Debe registrar al menos un lote o partición de mineral para esta unidad.');
            }
            foreach ($lotes as $lote) {
                if ($lote->peso_final === null) {
                    return ApiResponse::error("El lote {$lote->correlativo} no tiene registrado su peso final.");
                }
            }
            foreach ($particionesDeUnidad as $particion) {
                if ($particion->peso_final === null) {
                    return ApiResponse::error("La partición {$particion->correlativo} no tiene registrado su peso final.");
                }
            }
        }

        // NOTA: la validación de "padre debe estar finalizado" y "todas las
        // particiones del padre con peso_final" NO se hace acá. Esas reglas
        // son prerrequisito de `finalizar_particion_lote` (que exige ≥2
        // particiones Y todas las unidades anfitrionas en estado 'Pesado'),
        // no del cierre de proceso de una unidad individual. Cada unidad
        // puede (y debe) cerrar proceso de forma independiente antes de
        // que el padre se finalice.

        $recepcion->estado_pesaje = 'Pesado';
        $recepcion->fecha_hora_final_pesaje = now()->toDateTimeString();
        $recepcion->save();

        return ApiResponse::success(null, 'Proceso de balanza cerrado correctamente.');
    }

    /**
     * Obtener el resumen de balanza filtrado
     */
    public static function get_resumen_balanza(array $filters): array
    {
        if (empty($filters['id_sucursal'])) {
            return ApiResponse::error('Debe seleccionar una sucursal.');
        }

        $data = RecepcionMineralData::get_resumen_balanza($filters);

        return ApiResponse::success($data, 'Resumen de balanza obtenido correctamente.');
    }

    /**
     * Actualizar toda la información de un lote de mineral (para Resumen de Balanza)
     */
    public static function actualizar_lote(int $loteId, array $data, array $archivos, ?int $idEmpleado = null): array
    {
        $lote = LoteMineral::find($loteId);
        if (! $lote) {
            return ApiResponse::error('No se encontró el registro de lote.');
        }

        $cambios = [];
        $motivo = $data['motivo'] ?? null;

        // 1. Validar cambio de condición de ingreso y generar nuevo correlativo si aplica
        $oldCondicion = $lote->condicion_ingreso;
        $newCondicion = $data['condicion_ingreso'];

        if ($oldCondicion !== $newCondicion) {
            $isComercial = $newCondicion === CondicionIngreso::Comercializacion->value;
            $prefijo = $isComercial ? 'FB' : 'LOT';
            $filtros = $isComercial
                ? ['id_empresa' => $lote->id_empresa, 'condicion_ingreso' => CondicionIngreso::Comercializacion->value]
                : ['condicion_ingreso' => ['!=', CondicionIngreso::Comercializacion->value]];

            $cambios[] = [
                'campo_bd' => 'condicion_ingreso',
                'campo' => 'Condición de ingreso',
                'valor_anterior' => $oldCondicion,
                'valor_nuevo' => $newCondicion,
            ];

            // Sólo regenerar correlativo si el lote NO fue creado con código manual.
            // Si tiene con_codigo_manual=true, preservamos correlativo y numero_correlativo tal cual.
            if (! $lote->con_codigo_manual) {
                // Generar correlativo usando CorrelativoHelper
                $correlativoData = CorrelativoHelper::generar(
                    tabla: 'lote_mineral',
                    prefijo: $prefijo,
                    filtros: $filtros,
                    longitudCeros: 5,
                    reseteo: Periodo::Anual
                );

                $cambios[] = [
                    'campo_bd' => 'correlativo',
                    'campo' => 'Correlativo del lote',
                    'valor_anterior' => $lote->correlativo,
                    'valor_nuevo' => $correlativoData['correlativo'],
                ];

                $lote->correlativo = $correlativoData['correlativo'];
                $lote->numero_correlativo = $correlativoData['numero_correlativo'];
            }

            $lote->condicion_ingreso = $newCondicion;
        }

        $ru = \DB::table('recepcion_unidad')->where('id', $lote->id_recepcion_unidad)->first();

        // 2. Comparar el resto de los campos editables
        $camposAuditar = [
            'id_proveedor_minero' => [
                'nombre' => 'Proveedor minero',
                'tipo' => 'int',
                'resolver' => function ($id) {
                    if (! $id) {
                        return null;
                    }
                    $p = \DB::table('proveedor')->where('id', $id)->first();

                    return $p ? $p->razon_social : "ID #$id";
                },
            ],
            'id_zona_origen' => [
                'nombre' => 'Zona de origen',
                'tipo' => 'int',
                'resolver' => function ($id) {
                    if (! $id) {
                        return null;
                    }
                    $z = \DB::table('zona_origen')->where('id', $id)->first();

                    return $z ? $z->nombre : "ID #$id";
                },
            ],
            'numero_contacto' => ['nombre' => 'Número de contacto', 'tipo' => 'string'],
            'tipo_producto' => ['nombre' => 'Tipo de producto', 'tipo' => 'string'],
            'tipo_mineral' => ['nombre' => 'Tipo de mineral', 'tipo' => 'string'],
            'peso_inicial' => ['nombre' => 'Peso inicial', 'tipo' => 'float'],
            'peso_final' => ['nombre' => 'Peso final (tara)', 'tipo' => 'float'],
        ];

        foreach ($camposAuditar as $campoBd => $meta) {
            $valAnt = $lote->$campoBd;
            $valNue = $data[$campoBd];

            // Normalizar tipos para la comparación
            if ($meta['tipo'] === 'int') {
                $valAnt = $valAnt !== null ? (int) $valAnt : null;
                $valNue = ($valNue !== null && $valNue !== '') ? (int) $valNue : null;
            } elseif ($meta['tipo'] === 'float') {
                $valAnt = $valAnt !== null ? (float) $valAnt : null;
                $valNue = ($valNue !== null && $valNue !== '') ? (float) $valNue : null;
            } else {
                $valAnt = $valAnt !== null ? trim((string) $valAnt) : '';
                $valNue = $valNue !== null ? trim((string) $valNue) : '';
            }

            if ($valAnt !== $valNue) {
                $valAntLabel = isset($meta['resolver']) ? $meta['resolver']($valAnt) : $valAnt;
                $valNueLabel = isset($meta['resolver']) ? $meta['resolver']($valNue) : $valNue;

                $cambios[] = [
                    'campo_bd' => $campoBd,
                    'campo' => $meta['nombre'],
                    'valor_anterior' => $valAntLabel,
                    'valor_nuevo' => $valNueLabel,
                ];
            }
        }

        // Evidencias: manejar existentes y agregar nuevas
        $evidenciasGuardadas = [];
        if (array_key_exists('evidencias_existentes', $data)) {
            $evidenciasGuardadas = is_array($data['evidencias_existentes'])
                ? $data['evidencias_existentes']
                : (json_decode($data['evidencias_existentes'], true) ?? []);
        } else {
            $evidenciasGuardadas = $lote->evidencias ?? [];
        }

        if (! empty($archivos)) {
            $nuevosArchivos = ArchivoHelper::guardarArchivos('lotes', $archivos);
            $evidenciasGuardadas = array_merge($evidenciasGuardadas, $nuevosArchivos);
        }

        // Comparar evidencias
        $vAntEvidencias = $lote->evidencias ?? [];
        if (! is_array($vAntEvidencias)) {
            $vAntEvidencias = json_decode($vAntEvidencias, true) ?? [];
        }
        $nombresAnt = [];
        foreach ($vAntEvidencias as $e) {
            if (isset($e['nombre_original'])) {
                $nombresAnt[] = $e['nombre_original'];
            }
        }
        $nombresNue = [];
        foreach ($evidenciasGuardadas as $e) {
            if (isset($e['nombre_original'])) {
                $nombresNue[] = $e['nombre_original'];
            }
        }

        sort($nombresAnt);
        sort($nombresNue);

        if ($nombresAnt !== $nombresNue) {
            $cambios[] = [
                'campo_bd' => 'evidencias',
                'campo' => 'Evidencias',
                'valor_anterior' => ! empty($nombresAnt) ? implode(', ', $nombresAnt) : '— (sin evidencias)',
                'valor_nuevo' => ! empty($nombresNue) ? implode(', ', $nombresNue) : '— (sin evidencias)',
            ];
        }

        // 3. Registrar auditoría si hubo algún cambio
        if (! empty($cambios)) {
            $logActual = $lote->log_cambios ?? [];
            if (! is_array($logActual)) {
                $logActual = json_decode($logActual, true) ?? [];
            }
            $nuevoLog = [
                'id_empleado' => $idEmpleado,
                'motivo' => $motivo,
                'update_at' => now()->toDateTimeString(),
                'cambios' => $cambios,
            ];
            array_unshift($logActual, $nuevoLog);
            $lote->log_cambios = $logActual;
        }

        // Actualizar datos del lote
        $lote->id_proveedor_minero = $data['id_proveedor_minero'] ? (int) $data['id_proveedor_minero'] : null;
        $lote->id_zona_origen = $data['id_zona_origen'] ? (int) $data['id_zona_origen'] : null;
        $lote->numero_contacto = $data['numero_contacto'];
        $lote->tipo_producto = $data['tipo_producto'];
        $lote->tipo_mineral = $data['tipo_mineral'];
        $lote->peso_inicial = $data['peso_inicial'] !== null ? (float) $data['peso_inicial'] : null;

        $lote->peso_final = $data['peso_final'] !== null ? (float) $data['peso_final'] : null;

        // Calcular peso neto si ambos pesos existen
        if ($lote->peso_inicial !== null && $lote->peso_final !== null) {
            $lote->peso_neto = $lote->peso_inicial - $lote->peso_final;
            $lote->peso_actual = $lote->peso_neto; // Mantener peso_actual sincronizado con peso_neto
        } else {
            $lote->peso_neto = null;
            $lote->peso_actual = null;
        }

        $lote->evidencias = $evidenciasGuardadas;
        $lote->save();

        $updatedLote = RecepcionMineralData::get_lote_by_id($loteId);

        return ApiResponse::success($updatedLote, 'Lote actualizado correctamente.');
    }

    /**
     * Obtener los metadatos para los filtros de resumen de balanza
     */
    public static function get_resumen_filtros(int $idSucursal): array
    {
        $data = RecepcionMineralData::get_resumen_filtros($idSucursal);

        return ApiResponse::success($data, 'Metadatos de filtros obtenidos correctamente.');
    }

    /**
     * Listar las particiones activas de un lote padre particionado desde Balanza.
     */
    public static function listar_particiones(int $idLote): array
    {
        $lote = LoteMineral::find($idLote);
        if (! $lote) {
            return ApiResponse::error('No se encontró el lote padre.', 404);
        }

        $particiones = RecepcionMineralData::get_particiones_by_lote($idLote);

        return ApiResponse::success($particiones, 'Particiones obtenidas correctamente.');
    }

    /**
     * Detalle de una partición.
     */
    public static function get_particion(int $idParticion): array
    {
        $particion = RecepcionMineralData::get_particion_by_id($idParticion);
        if (! $particion) {
            return ApiResponse::error('No se encontró la partición.', 404);
        }

        return ApiResponse::success($particion, 'Partición obtenida correctamente.');
    }

    /**
     * Crear una partición adicional para un lote padre particionado desde Balanza.
     * Se usa cuando el usuario arrastra el card del lote padre a otra unidad.
     *
     * Reglas:
     *  - El lote padre debe tener particionado_desde_balanza=true y particion_finalizada=false.
     *  - No puede existir ya una partición activa del mismo lote en la misma unidad destino.
     *  - La letra se asigna secuencialmente: A, B, C, ..., Z, AA, AB, ... según las existentes.
     */
    public static function crear_particion(int $idLote, int $idRecepcionUnidad, int $idEmpleadoRegistro): array
    {
        DB::beginTransaction();
        try {
            // Lock pesimista para evitar duplicados por concurrencia.
            $lote = DB::table('lote_mineral')->where('id', $idLote)->lockForUpdate()->first();
            if (! $lote) {
                DB::rollBack();

                return ApiResponse::error('No se encontró el lote padre.', 404);
            }

            if (! $lote->particionado_desde_balanza) {
                DB::rollBack();

                return ApiResponse::error('El lote no está particionado desde Balanza.', 422);
            }

            if ($lote->particion_finalizada) {
                DB::rollBack();

                return ApiResponse::error('El lote padre ya está finalizado. No se pueden crear más particiones.', 422);
            }

            if (RecepcionMineralData::existe_particion_en_unidad($idLote, $idRecepcionUnidad)) {
                DB::rollBack();

                return ApiResponse::error('Ya existe una partición activa de este lote en esa unidad.', 422);
            }

            // Calcular la siguiente letra.
            $letra = self::siguienteLetraParticion($idLote);

            DB::table('particion_lote_mineral')->insert([
                'id_lote_mineral' => $idLote,
                'id_ticket_balanza' => null,
                'id_recepcion_unidad' => $idRecepcionUnidad,
                'correlativo' => $lote->correlativo.'-'.$letra,
                'particion' => $letra,
                'peso_inicial' => null,
                'fecha_hora_peso_inicial' => null,
                'peso_final' => null,
                'fecha_hora_peso_final' => null,
                'peso_neto' => null,
                'estado' => EstadoBase::Activo->value,
                'es_bloqueado' => false,
                'esta_validado' => true,
                'id_empleado_valida' => $idEmpleadoRegistro,
                'fecha_hora_validacion' => now()->toDateTimeString(),
                'evidencias' => null,
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return ApiResponse::error('Error al crear la partición: '.$e->getMessage(), 500);
        }

        $particiones = RecepcionMineralData::get_particiones_by_lote($idLote);

        return ApiResponse::success($particiones, 'Partición creada correctamente.');
    }

    /**
     * Devuelve la siguiente letra disponible (A, B, ..., Z, AA, AB, ...) según las
     * particiones activas del lote. La primera partición se crea como "A" al generar
     * el lote, así que esta función devuelve "B" la primera vez.
     */
    private static function siguienteLetraParticion(int $idLote): string
    {
        $existentes = DB::table('particion_lote_mineral')
            ->where('id_lote_mineral', $idLote)
            ->where('estado', EstadoBase::Activo->value)
            ->pluck('particion')
            ->all();

        if (empty($existentes)) {
            return 'A';
        }

        $maxIndex = -1;
        foreach ($existentes as $letra) {
            $idx = self::letraAIndice($letra);
            if ($idx !== null && $idx > $maxIndex) {
                $maxIndex = $idx;
            }
        }

        return self::indiceALetra($maxIndex + 1);
    }

    /**
     * Convierte "A" → 0, "B" → 1, ..., "Z" → 25, "AA" → 26, "AB" → 27, ...
     */
    private static function letraAIndice(string $letra): ?int
    {
        if ($letra === '' || ! ctype_upper($letra)) {
            return null;
        }
        $n = 0;
        $len = strlen($letra);
        for ($i = 0; $i < $len; $i++) {
            $v = ord($letra[$i]) - 65;
            if ($v < 0 || $v > 25) {
                return null;
            }
            $n = $n * 26 + ($v + 1);
        }

        return $n - 1;
    }

    private static function indiceALetra(int $n): string
    {
        $letra = '';
        $m = $n + 1;
        while ($m > 0) {
            $m--;
            $letra = chr(65 + ($m % 26)).$letra;
            $m = intdiv($m, 26);
        }

        return $letra;
    }

    /**
     * Eliminar (lógicamente) una partición. La fila se conserva en la tabla
     * `particion_lote_mineral` con `estado = 'Eliminado'`; los archivos físicos
     * de `evidencias` y el `ticket_balanza` asociado NO se borran del storage
     * (preservan la trazabilidad del pesaje y pueden consultarse por el log de
     * cambios del lote padre). Antes de la baja se registra un snapshot completo
     * en `lote_mineral.log_cambios`.
     *
     * Si tras la baja lógica no quedan particiones activas, se resetea
     * `tiene_particion=false` en el lote padre y se lo marca como `Eliminado`.
     */
    public static function eliminar_particion(int $idParticion, ?int $idEmpleado = null): array
    {
        DB::beginTransaction();
        try {
            $particion = ParticionLoteMineral::find($idParticion);
            if (! $particion) {
                DB::rollBack();

                return ApiResponse::error('No se encontró la partición.', 404);
            }

            if ($particion->estado === EstadoBase::Eliminado) {
                DB::rollBack();

                return ApiResponse::error('La partición ya está eliminada.', 422);
            }

            $idLote = (int) $particion->id_lote_mineral;
            $lote = LoteMineral::find($idLote);
            if (! $lote) {
                DB::rollBack();

                return ApiResponse::error('No se encontró el lote padre de la partición.', 404);
            }

            // Snapshot completo para el log de auditoría (se conserva antes
            // de cualquier cambio de estado para que el log refleje los valores
            // reales que tenía la partición al momento de la eliminación).
            $evidencias = is_array($particion->evidencias) ? $particion->evidencias : [];
            $snapshot = [
                'id_particion' => (int) $particion->id,
                'id_lote_mineral' => $idLote,
                'id_ticket_balanza' => $particion->id_ticket_balanza !== null ? (int) $particion->id_ticket_balanza : null,
                'id_recepcion_unidad' => $particion->id_recepcion_unidad !== null ? (int) $particion->id_recepcion_unidad : null,
                'correlativo' => (string) $particion->correlativo,
                'particion' => (string) $particion->particion,
                'peso_inicial' => $particion->peso_inicial !== null ? (float) $particion->peso_inicial : null,
                'peso_final' => $particion->peso_final !== null ? (float) $particion->peso_final : null,
                'estado' => $particion->estado instanceof EstadoBase ? $particion->estado->value : (string) $particion->estado,
                'es_bloqueado' => (bool) $particion->es_bloqueado,
                'esta_validado' => (bool) $particion->esta_validado,
                'evidencias_count' => count($evidencias),
            ];

            $cambios = [];
            foreach ($snapshot as $campoBd => $valor) {
                $cambios[] = [
                    'campo_bd' => "particion_lote_mineral.{$campoBd}",
                    'campo' => "Partición {$snapshot['particion']} ({$snapshot['correlativo']}) — {$campoBd}",
                    'valor_anterior' => $valor,
                    'valor_nuevo' => null,
                ];
            }

            // Baja lógica: cambiar estado. NO se borran archivos físicos ni
            // ticket_balanza (decisión del proyecto: conservar para auditoría).
            $particion->estado = EstadoBase::Eliminado;
            $particion->save();

            // Log de cambios en el lote padre (trazabilidad obligatoria).
            if ($idEmpleado !== null) {
                $logActual = $lote->log_cambios ?? [];
                if (! is_array($logActual)) {
                    $logActual = json_decode((string) $logActual, true) ?? [];
                }
                $nuevoLog = RES_CambiosLog::crear(
                    $idEmpleado,
                    'Eliminación lógica de partición',
                    $cambios,
                );
                array_unshift($logActual, $nuevoLog);
                $lote->log_cambios = $logActual;
                if ($lote->isDirty()) {
                    $lote->save();
                }
            }

            // Si no quedan particiones ACTIVAS, revertir flags del lote y
            // marcarlo como Eliminado para que desaparezca del grid y del
            // header global (las queries ya filtran por estado != "Eliminado").
            $quedanActivas = DB::table('particion_lote_mineral')
                ->where('id_lote_mineral', $idLote)
                ->where('estado', EstadoBase::Activo->value)
                ->count();
            if ($quedanActivas === 0) {
                DB::table('lote_mineral')->where('id', $idLote)->update([
                    'tiene_particion' => false,
                    'estado' => EstadoBase::Eliminado->value,
                ]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return ApiResponse::error('Error al eliminar la partición: '.$e->getMessage(), 500);
        }

        $particiones = RecepcionMineralData::get_particiones_by_lote($idLote);

        return ApiResponse::success($particiones, 'Partición eliminada correctamente.');
    }

    /**
     * Actualizar los campos no-peso del lote padre (cascada a todas las particiones).
     * Los campos editables: id_proveedor_minero, id_zona_origen, numero_contacto,
     * tipo_producto, tipo_mineral. Se persisten en lote_mineral y se devuelven
     * las particiones actualizadas (con campos del padre hidratados).
     *
     * @param  array{id_proveedor_minero?: int|null, id_zona_origen?: int|null, numero_contacto?: string|null, tipo_producto?: string|null, tipo_mineral?: string|null}  $data
     */
    public static function actualizar_campos_no_peso(int $idParticion, array $data, ?int $idEmpleado = null): array
    {
        $particion = ParticionLoteMineral::find($idParticion);
        if (! $particion) {
            return ApiResponse::error('No se encontró la partición.', 404);
        }

        $idLote = (int) $particion->id_lote_mineral;
        $lote = LoteMineral::find($idLote);
        if (! $lote) {
            return ApiResponse::error('No se encontró el lote padre.', 404);
        }

        $cambios = [];
        $campos = [
            'id_proveedor_minero' => ['tipo' => 'int', 'etiqueta' => 'Proveedor minero'],
            'id_zona_origen' => ['tipo' => 'int', 'etiqueta' => 'Zona de origen'],
            'numero_contacto' => ['tipo' => 'string', 'etiqueta' => 'Número de contacto'],
            'tipo_producto' => ['tipo' => 'string', 'etiqueta' => 'Tipo de producto'],
            'tipo_mineral' => ['tipo' => 'string', 'etiqueta' => 'Tipo de mineral'],
        ];

        foreach ($campos as $campo => $meta) {
            if (! array_key_exists($campo, $data)) {
                continue;
            }
            $ant = $lote->{$campo};
            $nue = $data[$campo];
            if ($meta['tipo'] === 'int') {
                $ant = $ant !== null ? (int) $ant : null;
                $nue = $nue !== null && $nue !== '' ? (int) $nue : null;
            } else {
                $ant = $ant !== null ? (string) $ant : null;
                $nue = $nue !== null ? (string) $nue : null;
            }
            if ($ant !== $nue) {
                $cambios[] = [
                    'campo_bd' => $campo,
                    'campo' => $meta['etiqueta'],
                    'valor_anterior' => $ant,
                    'valor_nuevo' => $nue,
                ];
                $lote->{$campo} = $data[$campo];
            }
        }

        if (! empty($cambios) && $idEmpleado !== null) {
            $logActual = $lote->log_cambios ?? [];
            if (! is_array($logActual)) {
                $logActual = json_decode($logActual, true) ?? [];
            }
            array_unshift($logActual, [
                'id_empleado' => $idEmpleado,
                'motivo' => 'Actualización desde partición',
                'update_at' => now()->toDateTimeString(),
                'cambios' => $cambios,
            ]);
            $lote->log_cambios = $logActual;
        }

        if ($lote->isDirty()) {
            $lote->save();
        }

        $particiones = RecepcionMineralData::get_particiones_by_lote($idLote);

        return ApiResponse::success([
            'particiones' => $particiones,
            'lote' => RecepcionMineralData::get_lote_by_id($idLote),
        ], 'Campos no-peso actualizados correctamente.');
    }

    /**
     * Registrar peso inicial de una partición. Crea el ticket de balanza si no tiene uno.
     * Devuelve la partición hidratada con el correlativo del ticket.
     *
     * Si la request incluye campos no-peso (id_proveedor_minero, id_zona_origen,
     * numero_contacto, tipo_producto, tipo_mineral), se persisten en el lote
     * padre para que las demás particiones los hereden vía JOIN al listarse.
     *
     * @param  array{peso_inicial: float, fecha_hora_peso_inicial?: string|null, observacion_peso_inicial?: string|null, evidencias_existentes?: array<int, mixed>|null, id_proveedor_minero?: int|null, id_zona_origen?: int|null, numero_contacto?: string|null, tipo_producto?: string|null, tipo_mineral?: string|null}  $data
     */
    public static function registrar_peso_inicial_particion(int $idParticion, array $data, array $archivos): array
    {
        DB::beginTransaction();
        try {
            $particion = ParticionLoteMineral::find($idParticion);
            if (! $particion) {
                DB::rollBack();

                return ApiResponse::error('No se encontró la partición.', 404);
            }

            if ($particion->estado !== EstadoBase::Activo) {
                DB::rollBack();

                return ApiResponse::error('La partición no está activa.', 422);
            }

            $pesoInicial = (float) ($data['peso_inicial'] ?? 0);
            if ($pesoInicial <= 0) {
                DB::rollBack();

                return ApiResponse::error('El peso inicial debe ser mayor a cero.', 422);
            }

            // Crear ticket de balanza si la partición aún no tiene uno.
            if ($particion->id_ticket_balanza === null) {
                $correlativoTicketData = CorrelativoHelper::generar(
                    tabla: 'ticket_balanza',
                    prefijo: '',
                    filtros: [],
                    longitudCeros: 0,
                    reseteo: Periodo::Diario,
                    formatoFecha: 'dmy',
                    incluirPrefijo: false,
                );

                $ticketId = DB::table('ticket_balanza')->insertGetId([
                    'correlativo' => $correlativoTicketData['correlativo'],
                    'numero_correlativo' => $correlativoTicketData['numero_correlativo'],
                    'created_at' => now(),
                ]);
                $particion->id_ticket_balanza = $ticketId;
            }

            // Evidencias: merge con existentes + archivos nuevos.
            $evidenciasActuales = $particion->evidencias ?? [];
            if (isset($data['evidencias_existentes'])) {
                $evidenciasActuales = is_array($data['evidencias_existentes'])
                    ? $data['evidencias_existentes']
                    : (json_decode($data['evidencias_existentes'], true) ?? []);
            }
            if (! empty($archivos)) {
                $nuevosArchivos = ArchivoHelper::guardarArchivos('particiones_lotes', $archivos);
                $evidenciasActuales = array_merge($evidenciasActuales, $nuevosArchivos);
            }

            $particion->peso_inicial = $pesoInicial;
            $particion->fecha_hora_peso_inicial = now()->toDateTimeString();
            $particion->evidencias = $evidenciasActuales;
            $particion->save();

            // Cascada de campos no-peso al lote padre (si vienen en la request).
            self::persistir_campos_no_peso_en_padre((int) $particion->id_lote_mineral, $data);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return ApiResponse::error('Error al registrar peso inicial de la partición: '.$e->getMessage(), 500);
        }

        $actualizada = RecepcionMineralData::get_particion_by_id($idParticion);

        return ApiResponse::success($actualizada, 'Peso inicial registrado correctamente.');
    }

    /**
     * Registrar peso final de una partición. Calcula peso_neto = peso_inicial - peso_final.
     *
     * Si la request incluye campos no-peso, también se persisten en el lote
     * padre (mismo criterio que `registrar_peso_inicial_particion`).
     *
     * @param  array{peso_final: float, fecha_hora_peso_final?: string|null, observacion_peso_final?: string|null, evidencias_existentes?: array<int, mixed>|null, id_proveedor_minero?: int|null, id_zona_origen?: int|null, numero_contacto?: string|null, tipo_producto?: string|null, tipo_mineral?: string|null}  $data
     */
    public static function registrar_peso_final_particion(int $idParticion, array $data): array
    {
        $particion = ParticionLoteMineral::find($idParticion);
        if (! $particion) {
            return ApiResponse::error('No se encontró la partición.', 404);
        }

        if ($particion->peso_inicial === null) {
            return ApiResponse::error('Debe registrar primero el peso inicial de la partición.', 422);
        }

        $pesoFinal = (float) ($data['peso_final'] ?? 0);
        if ($pesoFinal <= 0) {
            return ApiResponse::error('El peso final debe ser mayor a cero.', 422);
        }
        if ($pesoFinal >= (float) $particion->peso_inicial) {
            return ApiResponse::error('El peso final no puede ser mayor o igual al peso inicial.', 422);
        }

        $pesoNeto = round(((float) $particion->peso_inicial) - $pesoFinal, 2);

        $particion->peso_final = $pesoFinal;
        $particion->fecha_hora_peso_final = now()->toDateTimeString();
        $particion->peso_neto = $pesoNeto;
        $particion->save();

        // Cascada de campos no-peso al lote padre (si vienen en la request).
        self::persistir_campos_no_peso_en_padre((int) $particion->id_lote_mineral, $data);

        $actualizada = RecepcionMineralData::get_particion_by_id($idParticion);

        return ApiResponse::success($actualizada, 'Peso final registrado correctamente.');
    }

    /**
     * Persiste los campos no-peso en el lote padre de la partición (cascada).
     * Helper compartido por `registrar_peso_inicial_particion` y
     * `registrar_peso_final_particion`. Solo actualiza los campos que vienen
     * explícitamente en $data.
     *
     * @param  array{id_proveedor_minero?: mixed, id_zona_origen?: mixed, numero_contacto?: mixed, tipo_producto?: mixed, tipo_mineral?: mixed}  $data
     */
    private static function persistir_campos_no_peso_en_padre(int $idLote, array $data): void
    {
        $campos = [
            'id_proveedor_minero',
            'id_zona_origen',
            'numero_contacto',
            'tipo_producto',
            'tipo_mineral',
        ];

        $hayCambios = false;
        foreach ($campos as $campo) {
            if (array_key_exists($campo, $data)) {
                $hayCambios = true;
                break;
            }
        }
        if (! $hayCambios) {
            return;
        }

        $lote = LoteMineral::find($idLote);
        if (! $lote) {
            return;
        }

        foreach ($campos as $campo) {
            if (! array_key_exists($campo, $data)) {
                continue;
            }
            $valor = $data[$campo];
            // Normalizar '' → null para strings opcionales (numero_contacto,
            // tipo_producto, tipo_mineral) para mantener consistencia con
            // cómo se persisten desde otras rutas.
            if (in_array($campo, ['numero_contacto', 'tipo_producto', 'tipo_mineral'], true) && $valor === '') {
                $valor = null;
            }
            $lote->{$campo} = $valor;
        }

        if ($lote->isDirty()) {
            $lote->save();
        }
    }

    /**
     * Finalizar el lote padre particionado desde Balanza.
     * Suma los peso_neto de las particiones activas y los asigna como peso_neto_oficial
     * y peso_actual del lote padre. Sella particion_finalizada + fecha/empleado.
     * Valida que TODAS las particiones activas tengan peso_final.
     */
    public static function finalizar_particion_lote(int $idLote, int $idEmpleado): array
    {
        error_log("[finalizar_particion_lote] START idLote={$idLote} idEmpleado={$idEmpleado}");
        DB::beginTransaction();
        try {
            $lote = LoteMineral::find($idLote);
            if (! $lote) {
                DB::rollBack();
                error_log("[finalizar_particion_lote] 404 lote no encontrado");

                return ApiResponse::error('No se encontró el lote.', 404);
            }
            error_log('[finalizar_particion_lote] lote encontrado ' . json_encode([
                'particionado_desde_balanza' => (bool) $lote->particionado_desde_balanza,
                'particion_finalizada' => (bool) $lote->particion_finalizada,
                'estado' => $lote->estado,
            ]));

            if (! $lote->particionado_desde_balanza) {
                DB::rollBack();
                error_log('[finalizar_particion_lote] 422 no particionado desde balanza');

                return ApiResponse::error('El lote no está particionado desde Balanza.', 422);
            }

            if ($lote->particion_finalizada) {
                DB::rollBack();
                error_log('[finalizar_particion_lote] 422 ya finalizado');

                return ApiResponse::error('El lote ya fue finalizado.', 422);
            }

            $sinPesoFinal = RecepcionMineralData::count_particiones_sin_peso_final($idLote);
            error_log("[finalizar_particion_lote] sinPesoFinal={$sinPesoFinal}");
            if ($sinPesoFinal > 0) {
                DB::rollBack();
                error_log("[finalizar_particion_lote] 422 hay {$sinPesoFinal} particiones sin peso final");

                return ApiResponse::error(
                    "No se puede finalizar: hay {$sinPesoFinal} partición(es) sin peso final registrado.",
                );
            }

            // Regla: el padre debe tener al menos 2 particiones activas para finalizar.
            $totalParticiones = RecepcionMineralData::count_particiones_activas($idLote);
            error_log("[finalizar_particion_lote] totalParticiones={$totalParticiones}");
            if ($totalParticiones < 2) {
                DB::rollBack();

                return ApiResponse::error(
                    "No se puede finalizar: se requieren al menos 2 particiones (hay {$totalParticiones}).",
                    422,
                );
            }

            // Regla: cada partición debe vivir en una unidad con `estado_pesaje = 'Pesado'`,
            // lo cual se logra cerrando el proceso de esa unidad anfitriona.
            $particionesUnidades = RecepcionMineralData::get_particiones_estado_pesaje_unidades($idLote);
            $sinCerrar = array_values(array_filter(
                $particionesUnidades,
                static fn ($p) => ($p['estado_pesaje'] ?? null) !== 'Pesado',
            ));
            if (! empty($sinCerrar)) {
                DB::rollBack();
                $letras = implode(', ', array_map(static fn ($p) => $p['particion'], $sinCerrar));
                $detalle = array_map(
                    static fn ($p) => sprintf(
                        '%s (unidad %s, estado "%s")',
                        $p['particion'],
                        $p['id_recepcion_unidad'] ?? '—',
                        $p['estado_pesaje'] ?? 'sin unidad',
                    ),
                    $sinCerrar,
                );
                error_log('[finalizar_particion_lote] 422 particiones en unidades no Pesado: '.implode(' | ', $detalle));

                return ApiResponse::error(
                    'No se puede finalizar: debe cerrar el proceso de las unidades anfitrionas de las particiones '.$letras.' antes de finalizar el lote padre.',
                    422,
                );
            }

            $totalNeto = RecepcionMineralData::sum_peso_neto_particiones($idLote);
            error_log("[finalizar_particion_lote] totalNeto={$totalNeto}");

            $now = now()->toDateTimeString();
            $lote->peso_neto_oficial = round($totalNeto, 2);
            $lote->peso_actual = round($totalNeto, 2);
            $lote->particion_finalizada = true;
            $lote->id_empleado_fin_particion = $idEmpleado;
            $lote->fecha_hora_fin_particion = $now;
            $lote->save();
            error_log('[finalizar_particion_lote] lote guardado OK');

            // Auto-validación en validacion-distribucion: al finalizar el lote
            // padre, el lote y todas sus particiones activas quedan validadas.
            // El listado de validacion-distribucion los mostrará como ya
            // validados (check verde) sin paso manual del operador.
            $nowValidacion = now()->toDateTimeString();
            DB::table('particion_lote_mineral')
                ->where('id_lote_mineral', $idLote)
                ->where('estado', EstadoBase::Activo->value)
                ->update([
                    'esta_validado' => 1,
                    'id_empleado_valida' => $idEmpleado,
                    'fecha_hora_validacion' => $nowValidacion,
                ]);
            $lote->esta_validado = 1;
            $lote->id_empleado_valida = $idEmpleado;
            $lote->fecha_hora_validacion = $nowValidacion;
            $lote->save();

            DB::commit();
            error_log('[finalizar_particion_lote] COMMIT OK');
        } catch (\Throwable $e) {
            DB::rollBack();
            error_log('[finalizar_particion_lote] THROWABLE: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
            error_log('[finalizar_particion_lote] TRACE: '.$e->getTraceAsString());

            return ApiResponse::error('Error al finalizar el lote particionado: '.$e->getMessage(), 500);
        }

        $loteDetalle = RecepcionMineralData::get_lote_by_id($idLote);
        $particiones = RecepcionMineralData::get_particiones_by_lote($idLote);
        error_log('[finalizar_particion_lote] devolviendo respuesta success');

        return ApiResponse::success([
            'lote' => $loteDetalle,
            'particiones' => $particiones,
        ], 'Lote particionado finalizado correctamente.');
    }

    /**
     * Lotes padre particionados desde Balanza con particiones activas en una sucursal.
     * Sirve para alimentar el header global del frontend.
     */
    public static function get_lotes_padre_particionados(int $idSucursal): array
    {
        $rows = RecepcionMineralData::get_lotes_padre_particionados_by_sucursal($idSucursal);

        return ApiResponse::success($rows, 'Lotes padre particionados obtenidos correctamente.');
    }

    /**
     * Metadatos para imprimir el Ticket de Balanza de una PARTICIÓN de Balanza.
     *
     * Usa la query SQL dedicada `get_ticket_balanza_info_particion`, que pivota
     * desde `particion_lote_mineral` y resuelve todos los JOINs contra la UNIDAD
     * DESTINO de la partición (`plm.id_recepcion_unidad`):
     *  - ticket propio de la partición (`tb.id = plm.id_ticket_balanza`)
     *  - placa, conductor, transportista y operador desde la unidad destino
     *  - proveedor con cascada `rec.id_proveedor_minero → gui.id_proveedor → lot.id_proveedor_minero`
     *
     * Antes esta función reusaba `get_ticket_balanza_info($particion->id_lote_mineral)`,
     * que devolvía los datos del LOTE PADRE (ticket, unidad, operador, etc.) — no
     * los de la partición. Ahora cada módulo imprime correctamente para su tipo.
     *
     * Los overrides defensivos de `peso_bruto`/`peso_tara`/`peso_neto` se mantienen
     * por si la query dedicada deja `null` (defensa en profundidad).
     */
    public static function get_ticket_balanza_particion(int $idParticion): array
    {
        $particion = RecepcionMineralData::get_particion_by_id($idParticion);
        if (! $particion) {
            return ApiResponse::error('No se encontró la partición.', 404);
        }

        if ($particion->id_ticket_balanza === null) {
            return ApiResponse::error('La partición aún no tiene ticket de balanza. Registre primero el peso inicial.', 422);
        }

        $data = RecepcionMineralData::get_ticket_balanza_info_particion($idParticion);
        if (! $data) {
            return ApiResponse::error('No se encontró la información del ticket para la partición especificada.', 404);
        }

        // Defenderse por si la query dedicada dejó los pesos en null (no debería,
        // pero preserva el comportamiento histórico por si cambia el contrato).
        if ($particion->peso_inicial !== null) {
            $data['peso_bruto'] = (float) $particion->peso_inicial;
            $data['fecha_hora_peso_bruto'] = $particion->fecha_hora_peso_inicial;
        }
        if ($particion->peso_final !== null) {
            $data['peso_tara'] = (float) $particion->peso_final;
            $data['fecha_hora_peso_tara'] = $particion->fecha_hora_peso_final;
        }
        if ($particion->peso_neto !== null) {
            $data['peso_neto'] = (float) $particion->peso_neto;
        }

        // Metadato adicional por si el frontend lo requiere en el futuro.
        $data['particion'] = $particion->particion;

        return ApiResponse::success($data, 'Ticket de balanza de la partición obtenido correctamente.');
    }
}
