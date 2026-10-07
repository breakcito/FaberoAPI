<?php

namespace App\Modules\CierreLeyes\Services;

use App\Modules\CierreLeyes\Data\CierreLeyesData;
use App\Shared\Enums\_Generic\EstadoLeyes;
use App\Shared\Responses\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CierreLeyesService
{
    /**
     * Obtener los lotes sugeridos pendientes de análisis.
     */
    public static function get_lotes_sugeridos(): array
    {
        $data = CierreLeyesData::get_lotes_sugeridos();

        return ApiResponse::success($data, 'Lotes sugeridos obtenidos correctamente');
    }

    /**
     * Iniciar el proceso de análisis de leyes para un lote.
     */
    public static function iniciar_lote(int $idLote, int $idEmpleado): array
    {
        $lote = CierreLeyesData::get_lote_by_id($idLote);
        if (! $lote) {
            return ApiResponse::error('Lote no encontrado');
        }

        if ($lote->getRawOriginal('estado_leyes') !== EstadoLeyes::Pendiente->value) {
            return ApiResponse::error('El lote no se encuentra en estado Pendiente');
        }

        DB::beginTransaction();
        try {
            CierreLeyesData::actualizar_estado_inicio_lote($lote, $idEmpleado);

            $uuidFila = Str::uuid()->toString();
            CierreLeyesData::crear_registros_vacios_analisis($idLote, $uuidFila, $idEmpleado);

            DB::commit();

            $updatedLote = CierreLeyesData::get_lotes_cierre($idLote);
            $loteData = count($updatedLote) > 0 ? $updatedLote[0] : null;

            return ApiResponse::success($loteData, 'Análisis de leyes iniciado correctamente para el lote');
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al iniciar el lote: '.$e->getMessage());
        }
    }

    /**
     * Agregar una nueva corrida de análisis a un lote en proceso.
     */
    public static function agregar_analisis(int $idLote, int $idEmpleado): array
    {
        $lote = CierreLeyesData::get_lote_by_id($idLote);
        if (! $lote) {
            return ApiResponse::error('Lote no encontrado');
        }

        if ($lote->getRawOriginal('estado_leyes') !== EstadoLeyes::EnProceso->value) {
            return ApiResponse::error('El lote no se encuentra en proceso de análisis');
        }

        DB::beginTransaction();
        try {
            $uuidFila = Str::uuid()->toString();
            CierreLeyesData::crear_registros_vacios_analisis($idLote, $uuidFila, $idEmpleado);

            DB::commit();

            $updatedLote = CierreLeyesData::get_lotes_cierre($idLote);
            $loteData = count($updatedLote) > 0 ? $updatedLote[0] : null;

            return ApiResponse::success($loteData, 'Nuevo análisis agregado correctamente');
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al agregar análisis: '.$e->getMessage());
        }
    }

    /**
     * Obtener el listado de lotes en proceso o confirmados para el cierre de leyes.
     */
    public static function get_lotes_cierre(?string $estado = null, ?string $fechaInicio = null, ?string $fechaFin = null, ?int $id = null): array
    {
        $filtros = [];

        if ($id !== null) {
            $filtros['id_lookup'] = $id;
        }

        if ($estado !== null && $estado !== '' && $estado !== 'Todos') {
            $filtros['estados'] = [$estado];
        }

        if ($fechaInicio !== null && $fechaInicio !== '') {
            $filtros['fecha_inicio'] = $fechaInicio;
        }

        if ($fechaFin !== null && $fechaFin !== '') {
            $filtros['fecha_fin'] = $fechaFin;
        }

        $idLookup = $filtros['id_lookup'] ?? null;
        unset($filtros['id_lookup']);

        $data = CierreLeyesData::get_lotes_cierre($idLookup, $filtros);

        return ApiResponse::success($data, 'Lotes en proceso/confirmados obtenidos correctamente');
    }

    /**
     * Guardar o actualizar el valor de una ley en el análisis mineral.
     */
    public static function guardar_valor_ley(
        int $idLoteMineral,
        int $idGrupoAnalisisDetalle,
        ?string $tipoOrigen,
        string $uuidFila,
        float $ley,
        bool $estaConfirmada,
        int $idEmpleadoRegistro,
        ?int $id = null
    ): array {
        if ($estaConfirmada && $ley <= 0) {
            return ApiResponse::error('No se puede confirmar un análisis sin un valor mayor a cero.');
        }

        DB::beginTransaction();
        try {
            $detalle = CierreLeyesData::get_detalle_con_analito($idGrupoAnalisisDetalle);
            if (! $detalle) {
                return ApiResponse::error('El detalle del grupo de análisis no existe');
            }

            $esDesplegable = $detalle->analito ? (bool) $detalle->analito->es_desplegable : false;

            if (! $esDesplegable) {
                $affected = CierreLeyesData::actualizar_leyes_no_desplegables(
                    $idLoteMineral,
                    $idGrupoAnalisisDetalle,
                    $ley,
                    $estaConfirmada,
                    $idEmpleadoRegistro
                );

                if ($affected === 0) {
                    CierreLeyesData::crear_analisis_mineral([
                        'id_lote_mineral' => $idLoteMineral,
                        'id_grupo_analisis_detalle' => $idGrupoAnalisisDetalle,
                        'tipo_origen' => $tipoOrigen,
                        'uuid_fila' => $uuidFila,
                        'ley' => $ley,
                        'esta_confirmada' => $estaConfirmada ? 1 : 0,
                        'id_empleado_registro' => $idEmpleadoRegistro,
                    ]);
                }
            } else {
                if ($id !== null) {
                    $registro = CierreLeyesData::get_registro_analisis_by_id($id);
                    if (! $registro) {
                        return ApiResponse::error('Registro de análisis no encontrado para actualizar');
                    }
                    CierreLeyesData::actualizar_registro_analisis($registro, $ley, $estaConfirmada, $idEmpleadoRegistro);
                } else {
                    CierreLeyesData::crear_analisis_mineral([
                        'id_lote_mineral' => $idLoteMineral,
                        'id_grupo_analisis_detalle' => $idGrupoAnalisisDetalle,
                        'tipo_origen' => $tipoOrigen,
                        'uuid_fila' => $uuidFila,
                        'ley' => $ley,
                        'esta_confirmada' => $estaConfirmada ? 1 : 0,
                        'id_empleado_registro' => $idEmpleadoRegistro,
                    ]);
                }
            }

            DB::commit();

            $updatedLote = CierreLeyesData::get_lotes_cierre($idLoteMineral);
            $loteData = count($updatedLote) > 0 ? $updatedLote[0] : null;

            return ApiResponse::success($loteData, 'Valor de ley guardado correctamente');
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al guardar el valor de ley: '.$e->getMessage());
        }
    }

    /**
     * Eliminar un registro individual de ley.
     */
    public static function eliminar_valor(int $id): array
    {
        $registro = CierreLeyesData::get_registro_analisis_by_id($id);
        if (! $registro) {
            return ApiResponse::error('Registro de análisis no encontrado');
        }

        $idLoteMineral = (int) $registro->id_lote_mineral;
        CierreLeyesData::eliminar_registro_analisis($registro);

        $updatedLote = CierreLeyesData::get_lotes_cierre($idLoteMineral);
        $loteData = count($updatedLote) > 0 ? $updatedLote[0] : null;

        return ApiResponse::success($loteData, 'Registro de análisis eliminado correctamente');
    }

    /**
     * Eliminar todas las celdas asociadas a una corrida de análisis por su uuid_fila.
     */
    public static function eliminar_fila(int $idLoteMineral, string $uuidFila): array
    {
        CierreLeyesData::eliminar_fila_analisis($idLoteMineral, $uuidFila);

        $updatedLote = CierreLeyesData::get_lotes_cierre($idLoteMineral);
        $loteData = count($updatedLote) > 0 ? $updatedLote[0] : null;

        return ApiResponse::success($loteData, 'Fila de análisis eliminada correctamente');
    }

    /**
     * Validar si un lote cumple las condiciones de cierre.
     * Cada analito activo marcado para valorización debe tener al menos un análisis confirmado y con ley > 0.
     *
     * @return array{ok: bool, motivo?: string}
     */
    private static function validar_cierre(int $idLote): array
    {
        $detallesValorizar = CierreLeyesData::get_detalles_para_valorizacion();

        if (empty($detallesValorizar)) {
            return ['ok' => true];
        }

        $analisisLote = CierreLeyesData::get_filas_analisis_por_lote($idLote);

        foreach ($detallesValorizar as $detalle) {
            $tieneConfirmadoValido = $analisisLote->contains(function ($reg) use ($detalle) {
                return (int) $reg->id_grupo_analisis_detalle === (int) $detalle->detalle_id
                    && (bool) $reg->esta_confirmada
                    && $reg->ley !== null
                    && (float) $reg->ley > 0;
            });

            if (! $tieneConfirmadoValido) {
                return [
                    'ok' => false,
                    'motivo' => "El analito '{$detalle->analito_nombre}' requiere al menos un análisis confirmado con un valor mayor a cero.",
                ];
            }
        }

        return ['ok' => true];
    }

    /**
     * Consolidar las leyes representativas del lote calculando promedios para analitos desplegables o valor único para no desplegables.
     * Si el front envia leyes manuales (id_detalle → ley), sobrescribe el cálculo automático SOLO en los detalles provistos.
     *
     * @param  array<int,float>|null  $leyesManuales
     */
    private static function consolidar_leyes(int $idLote, ?array $leyesManuales = null): array
    {
        $analisisConfirmados = CierreLeyesData::get_analisis_confirmados_por_lote($idLote);

        $leyesValores = [
            'ley_oro' => 0.0,
            'ley_plata' => 0.0,
            'ley_humedad' => 0.0,
            'ley_recuperacion' => 0.0,
        ];

        $agrupados = $analisisConfirmados->groupBy('id_grupo_analisis_detalle');

        foreach ($agrupados as $idDetalle => $registros) {
            $detalle = CierreLeyesData::get_detalle_con_analito((int) $idDetalle);
            if (! $detalle) {
                continue;
            }

            // Override manual del front: si llega un valor para este detalle, gana sobre el cálculo automático.
            if ($leyesManuales !== null && array_key_exists((int) $idDetalle, $leyesManuales)) {
                $valor = (float) $leyesManuales[(int) $idDetalle];
            } else {
                $esDesplegable = $detalle->analito ? (bool) $detalle->analito->es_desplegable : false;

                if ($esDesplegable) {
                    $sumaLeyes = 0.0;
                    $cant = 0;
                    foreach ($registros as $reg) {
                        $sumaLeyes += (float) $reg->ley;
                        $cant++;
                    }
                    $valor = $cant > 0 ? $sumaLeyes / $cant : 0.0;
                } else {
                    $valor = (float) ($registros[0]->ley ?? 0.0);
                }
            }

            if ((bool) $detalle->para_valorizacion_oro) {
                $leyesValores['ley_oro'] = $valor;
            }
            if ((bool) $detalle->para_valorizacion_plata) {
                $leyesValores['ley_plata'] = $valor;
            }
            if ((bool) $detalle->para_valorizacion_humedad) {
                $leyesValores['ley_humedad'] = $valor;
            }
            if ((bool) $detalle->para_valorizacion_recuperacion) {
                $leyesValores['ley_recuperacion'] = $valor;
            }
        }

        return $leyesValores;
    }

    /**
     * Calcular el promedio de humedad y recuperación del lote considerando TODAS las filas
     * con `ley` definido (incluye 0 y filas migradas desde muestras con `esta_confirmada=0`).
     * Usado al asociar muestras para que el valor consolidado del lote se mantenga en
     * sincronía con las filas reales, sin esperar a la confirmación formal del lote.
     *
     * @return array{ley_humedad: float, ley_recuperacion: float}
     */
    private static function consolidar_humedad_recuperacion(int $idLote): array
    {
        $rows = CierreLeyesData::get_humedad_recuperacion_rows_for_lote($idLote);

        $detalles = DB::table('grupo_analisis_detalle')
            ->select('id', 'para_valorizacion_humedad', 'para_valorizacion_recuperacion')
            ->where(function ($q) {
                $q->where('para_valorizacion_humedad', 1)
                    ->orWhere('para_valorizacion_recuperacion', 1);
            })
            ->get();

        $humedadIds = $detalles->where('para_valorizacion_humedad', 1)->pluck('id')->all();
        $recuperacionIds = $detalles->where('para_valorizacion_recuperacion', 1)->pluck('id')->all();

        $humVals = $rows->whereIn('id_grupo_analisis_detalle', $humedadIds);
        $recVals = $rows->whereIn('id_grupo_analisis_detalle', $recuperacionIds);

        return [
            'ley_humedad' => $humVals->count() > 0 ? (float) $humVals->avg('ley') : 0.0,
            'ley_recuperacion' => $recVals->count() > 0 ? (float) $recVals->avg('ley') : 0.0,
        ];
    }

    /**
     * Confirmar y cerrar el lote de leyes.
     *
     * @param  array<int,float>|null  $leyesManuales  Map id_detalle → ley cuando el front envia override; si null, consolidar automaticamente
     */
    public static function confirmar_lote_leyes(int $idLote, bool $conValorComercial, int $idEmpleado, ?array $leyesManuales = null): array
    {
        $lote = CierreLeyesData::get_lote_by_id($idLote);
        if (! $lote) {
            return ApiResponse::error('Lote no encontrado');
        }

        if ($lote->getRawOriginal('estado_leyes') !== EstadoLeyes::EnProceso->value) {
            return ApiResponse::error('El lote no se encuentra en proceso de análisis');
        }

        $validacion = self::validar_cierre($idLote);
        if (! $validacion['ok']) {
            return ApiResponse::error($validacion['motivo']);
        }

        DB::beginTransaction();
        try {
            $leyesValores = self::consolidar_leyes($idLote, $leyesManuales);
            CierreLeyesData::confirmar_y_cerrar_lote($lote, $leyesValores, $conValorComercial, $idEmpleado);

            DB::commit();

            $updatedLote = CierreLeyesData::get_lotes_cierre($idLote);
            $loteData = count($updatedLote) > 0 ? $updatedLote[0] : null;

            return ApiResponse::success($loteData, 'Lote de leyes confirmado y cerrado correctamente');
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al confirmar el cierre de leyes: '.$e->getMessage());
        }
    }

    /**
     * Actualizar el tipo de origen de una corrida de análisis.
     */
    public static function actualizar_origen_fila(int $idLoteMineral, string $uuidFila, ?string $tipoOrigen, int $idEmpleado = 1): array
    {
        CierreLeyesData::actualizar_origen_fila($idLoteMineral, $uuidFila, $tipoOrigen, $idEmpleado);

        $updatedLote = CierreLeyesData::get_lotes_cierre($idLoteMineral);
        $loteData = count($updatedLote) > 0 ? $updatedLote[0] : null;

        return ApiResponse::success($loteData, 'Origen de la corrida actualizado correctamente');
    }

    // ===== MUESTRAS EXTERNAS (pre-análisis de un lote que aún no existe) =====

    /**
     * Iniciar una muestra externa: inserta la cabecera con correlativo "RC-NN" (Periodo::Ninguno)
     * y crea una corrida de analisis_mineral asociada por id_muestra_externa con sin_lote=1.
     *
     * @return array{data?: array, success?: bool, message?: string}
     */
    public static function iniciar_muestra_externa(int $idProveedorMinero, int $idEmpleado): array
    {
        DB::beginTransaction();
        try {
            // Generar siguiente número correlativo monotónico (formato "RC-NN", sin reinicio de tiempo)
            $siguienteNumero = ((int) DB::table('muestra_externa')->max('numero_correlativo')) + 1;
            $correlativo = 'RC-'.str_pad((string) $siguienteNumero, 2, '0', STR_PAD_LEFT);

            $idMuestra = CierreLeyesData::crear_muestra_externa([
                'id_empleado_registro' => $idEmpleado,
                'id_proveedor_minero' => $idProveedorMinero,
                'correlativo' => $correlativo,
                'numero_correlativo' => $siguienteNumero,
            ]);

            $uuidFila = Str::uuid()->toString();
            CierreLeyesData::crear_registros_vacios_analisis_muestra($idMuestra, $uuidFila, $idEmpleado);

            DB::commit();

            $muestra = CierreLeyesData::get_muestra_externa_by_id($idMuestra);

            return ApiResponse::success($muestra, 'Muestra externa iniciada correctamente');
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al iniciar la muestra externa: '.$e->getMessage());
        }
    }

    /**
     * Listar muestras externas activas (aún no asociadas a un lote).
     */
    public static function get_muestras_externas(): array
    {
        $data = CierreLeyesData::get_muestras_externas_activas();

        return ApiResponse::success($data, 'Muestras externas activas obtenidas correctamente');
    }

    /**
     * Guardar o actualizar el valor de una ley en una muestra externa (misma lógica que
     * un lote, apuntando a id_muestra_externa en vez de id_lote_mineral).
     */
    public static function guardar_valor_muestra_externa(
        int $idMuestraExterna,
        int $idGrupoAnalisisDetalle,
        ?string $tipoOrigen,
        string $uuidFila,
        float $ley,
        bool $estaConfirmada,
        int $idEmpleadoRegistro,
        ?int $id = null
    ): array {
        if ($estaConfirmada && $ley <= 0) {
            return ApiResponse::error('No se puede confirmar un análisis sin un valor mayor a cero.');
        }

        DB::beginTransaction();
        try {
            $detalle = CierreLeyesData::get_detalle_con_analito($idGrupoAnalisisDetalle);
            if (! $detalle) {
                return ApiResponse::error('El detalle del grupo de análisis no existe');
            }

            $esDesplegable = $detalle->analito ? (bool) $detalle->analito->es_desplegable : false;

            if (! $esDesplegable) {
                // No-desplegable: actualizar SOLO la corrida específica (mismo uuid_fila),
                // NO todas las corridas de la muestra para ese detalle.
                $affected = CierreLeyesData::actualizar_leyes_no_desplegables_muestra(
                    $idMuestraExterna,
                    $idGrupoAnalisisDetalle,
                    $ley,
                    $estaConfirmada,
                    $idEmpleadoRegistro,
                    $uuidFila
                );

                if ($affected === 0) {
                    CierreLeyesData::crear_analisis_mineral_muestra([
                        'id_muestra_externa' => $idMuestraExterna,
                        'id_grupo_analisis_detalle' => $idGrupoAnalisisDetalle,
                        'tipo_origen' => $tipoOrigen,
                        'uuid_fila' => $uuidFila,
                        'ley' => $ley,
                        'esta_confirmada' => $estaConfirmada ? 1 : 0,
                        'id_empleado_registro' => $idEmpleadoRegistro,
                    ]);
                }
            } else {
                if ($id !== null) {
                    $registro = CierreLeyesData::get_registro_analisis_by_id($id);
                    if (! $registro) {
                        return ApiResponse::error('Registro de análisis no encontrado para actualizar');
                    }
                    CierreLeyesData::actualizar_registro_analisis($registro, $ley, $estaConfirmada, $idEmpleadoRegistro);
                } else {
                    CierreLeyesData::crear_analisis_mineral_muestra([
                        'id_muestra_externa' => $idMuestraExterna,
                        'id_grupo_analisis_detalle' => $idGrupoAnalisisDetalle,
                        'tipo_origen' => $tipoOrigen,
                        'uuid_fila' => $uuidFila,
                        'ley' => $ley,
                        'esta_confirmada' => $estaConfirmada ? 1 : 0,
                        'id_empleado_registro' => $idEmpleadoRegistro,
                    ]);
                }
            }

            DB::commit();

            $muestra = CierreLeyesData::get_muestra_externa_by_id($idMuestraExterna);

            return ApiResponse::success($muestra, 'Valor de ley guardado correctamente');
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al guardar el valor de ley: '.$e->getMessage());
        }
    }

    /**
     * Eliminar una corrida de análisis de una muestra externa por uuid_fila.
     * Si después de eliminar la corrida la muestra queda sin análisis, se hace CASCADE
     * (se borra la cabecera de muestra_externa también) para no dejar muestras huérfanas.
     */
    public static function eliminar_fila_muestra_externa(int $idMuestraExterna, string $uuidFila): array
    {
        DB::beginTransaction();
        try {
            CierreLeyesData::eliminar_fila_analisis_muestra($idMuestraExterna, $uuidFila);

            $restantes = CierreLeyesData::count_analisis_by_muestra($idMuestraExterna);
            if ($restantes === 0) {
                CierreLeyesData::eliminar_muestra_externa($idMuestraExterna);
                DB::commit();

                return ApiResponse::success(null, 'Muestra externa eliminada por quedar sin registros');
            }

            $muestra = CierreLeyesData::get_muestra_externa_by_id($idMuestraExterna);
            DB::commit();

            return ApiResponse::success($muestra, 'Fila de análisis eliminada correctamente');
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al eliminar la fila de análisis: '.$e->getMessage());
        }
    }

    /**
     * Agregar una nueva corrida de análisis (nuevo uuid_fila) a una muestra externa.
     */
    public static function agregar_analisis_muestra(int $idMuestraExterna, int $idEmpleado): array
    {
        $muestra = CierreLeyesData::get_muestra_externa_by_id($idMuestraExterna);
        if (! $muestra) {
            return ApiResponse::error('Muestra externa no encontrada');
        }

        DB::beginTransaction();
        try {
            $uuidFila = Str::uuid()->toString();
            CierreLeyesData::crear_registros_vacios_analisis_muestra($idMuestraExterna, $uuidFila, $idEmpleado);

            DB::commit();

            $muestraActualizada = CierreLeyesData::get_muestra_externa_by_id($idMuestraExterna);

            return ApiResponse::success($muestraActualizada, 'Nuevo análisis agregado a la muestra externa');
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al agregar análisis a la muestra externa: '.$e->getMessage());
        }
    }

    /**
     * Actualizar tipo de origen de una corrida de análisis de una muestra externa.
     */
    public static function actualizar_origen_fila_muestra_externa(int $idMuestraExterna, string $uuidFila, ?string $tipoOrigen, int $idEmpleado = 1): array
    {
        CierreLeyesData::actualizar_origen_fila_muestra($idMuestraExterna, $uuidFila, $tipoOrigen, $idEmpleado);

        $muestra = CierreLeyesData::get_muestra_externa_by_id($idMuestraExterna);

        return ApiResponse::success($muestra, 'Origen de la corrida actualizado correctamente');
    }

    /**
     * Asociar una muestra externa a un lote: los analisis_mineral de la muestra
     * actualizan su id_lote_mineral al destino, id_muestra_externa=NULL, sin_lote=0.
     * Cada analisis_mineral afectado recibe una entrada en log_cambios indicando
     * la migración desde la muestra externa (mantiene su uuid_fila original).
     *
     * Reglas:
     *  - El lote destino debe estar en estado_leyes 'Pendiente' o 'En Proceso'.
     *  - Si el lote estaba Pendiente, se cambia automáticamente a EnProceso
     *    (es un "iniciar análisis" implícito con datos precargados).
     *  - Una muestra solo puede asociarse a un lote.
     */
    public static function asociar_muestra_a_lote(int $idMuestraExterna, int $idLoteMineral, int $idEmpleado): array
    {
        $lote = CierreLeyesData::get_lote_by_id($idLoteMineral);
        if (! $lote) {
            return ApiResponse::error('Lote no encontrado');
        }

        $estadoLeyes = $lote->getRawOriginal('estado_leyes');
        if ($estadoLeyes !== EstadoLeyes::Pendiente->value && $estadoLeyes !== EstadoLeyes::EnProceso->value) {
            return ApiResponse::error('Solo se pueden asociar muestras externas a lotes en estado Pendiente o En Proceso');
        }

        $muestra = CierreLeyesData::get_muestra_externa_by_id($idMuestraExterna);
        if (! $muestra) {
            return ApiResponse::error('Muestra externa no encontrada');
        }

        DB::beginTransaction();
        try {
            // Si el lote estaba Pendiente, lo abrimos a EnProceso (mismo efecto que iniciar_lote).
            $esPendiente = $estadoLeyes === EstadoLeyes::Pendiente->value;
            if ($esPendiente) {
                CierreLeyesData::actualizar_estado_inicio_lote($lote, $idEmpleado);
            }

            $migrados = CierreLeyesData::asociar_analisis_muestra_a_lote($idMuestraExterna, $idLoteMineral, $idEmpleado, $muestra);

            // Recalcular humedad/recuperacion con TODAS las filas (incluye las migradas,
            // que vienen con esta_confirmada=0) para que el consolidado del lote refleje
            // el promedio real de las filas tras la asociación. No toca oro/plata ni el
            // estado de confirmación del lote.
            $hr = self::consolidar_humedad_recuperacion($idLoteMineral);
            CierreLeyesData::actualizar_leyes_lote($lote, $hr);
            CierreLeyesData::actualizar_filas_humedad_recuperacion_a_promedio(
                $idLoteMineral,
                $hr['ley_humedad'],
                $hr['ley_recuperacion'],
            );

            DB::commit();

            $updatedLote = CierreLeyesData::get_lotes_cierre($idLoteMineral);
            $loteData = count($updatedLote) > 0 ? $updatedLote[0] : null;

            return ApiResponse::success([
                'lote' => $loteData,
                'muestra' => $muestra,
                'analisis_migrados' => $migrados,
                'lote_iniciado' => $esPendiente,
            ], 'Muestra externa asociada al lote correctamente');
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al asociar la muestra externa: '.$e->getMessage());
        }
    }

    /**
     * Listar las muestras externas que fueron asociadas a un lote específico.
     */
    public static function get_muestras_asociadas_por_lote(int $idLoteMineral): array
    {
        $data = CierreLeyesData::get_muestras_asociadas_por_lote($idLoteMineral);

        return ApiResponse::success($data, 'Muestras externas asociadas al lote obtenidas correctamente');
    }
}
