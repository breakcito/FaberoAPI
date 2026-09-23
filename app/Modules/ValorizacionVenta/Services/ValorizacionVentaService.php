<?php

namespace App\Modules\ValorizacionVenta\Services;

use App\Models\PlantaDestino;
use App\Models\ValorizacionVenta;
use App\Models\ValorizacionVentaDetalle;
use App\Modules\ValorizacionVenta\Data\ValorizacionVentaData;
use App\Shared\Enums\_Generic\ElementoQuimicoValorizacion;
use App\Shared\Enums\_Generic\Periodo;
use App\Shared\Enums\ValorizacionVenta\EstadoValorizacionVenta;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Helpers\CorrelativoHelper;
use App\Shared\Responses\ApiResponse;
use Exception;
use Illuminate\Support\Facades\DB;

class ValorizacionVentaService
{
    /**
     * Listar valorizaciones de venta
     */
    public static function listar_valorizaciones(?int $idPlanta = null): array
    {
        $data = ValorizacionVentaData::get_valorizaciones($idPlanta);

        return ApiResponse::success($data, 'Valorizaciones obtenidas correctamente.');
    }

    /**
     * Obtener una valorización por ID
     */
    public static function obtener_valorizacion(int $id): array
    {
        $data = ValorizacionVentaData::get_valorizacion_by_id($id);
        if (! $data) {
            return ApiResponse::error('Valorización no encontrada.');
        }

        return ApiResponse::success($data, 'Valorización obtenida correctamente.');
    }

    /**
     * Crear una nueva valorización en estado Pendiente
     *
     * @param  array<string,mixed>  $data
     * @param  \Illuminate\Http\UploadedFile[]  $archivos
     */
    public static function crear_valorizacion(array $data, array $archivos = []): array
    {
        DB::beginTransaction();
        try {
            $correlativoRes = CorrelativoHelper::generar(
                tabla: 'valorizacion_venta',
                prefijo: 'VV',
                filtros: [],
                longitudCeros: 5,
                reseteo: Periodo::Anual
            );

            $correlativoStr = (string) $correlativoRes['correlativo'];
            $numeroCorrelativo = (string) $correlativoRes['numero_correlativo'];

            $evidenciasGuardadas = ! empty($archivos)
                ? ArchivoHelper::guardarArchivos('valorizaciones_venta', $archivos)
                : [];

            $valorizacion = ValorizacionVenta::create([
                'id_planta' => (int) $data['id_planta'],
                'id_empleado_registro' => (int) $data['id_empleado_registro'],
                'numero_correlativo' => $numeroCorrelativo,
                'correlativo' => $correlativoStr,
                'fecha_hora_valorizacion' => $data['fecha_hora_valorizacion'] ?? null,
                'codigo' => $data['codigo'] ?? null,
                'evidencias' => ! empty($evidenciasGuardadas) ? array_values($evidenciasGuardadas) : null,
                'monto_penalidad' => $data['monto_penalidad'] ?? 0,
                'monto_flete' => $data['monto_flete'] ?? 0,
                'created_at' => now(),
                'estado' => EstadoValorizacionVenta::Pendiente->value,
            ]);

            // Guardar Detalles de distribuciones_detalle
            $detallesCreados = [];
            foreach ($data['detalles'] as $det) {
                $ddt = ValorizacionVentaData::find_distribucion_detalle_con_planta((int) $det['id_distribucion_detalle']);
                if (! $ddt) {
                    throw new Exception("La distribucion_detalle ID {$det['id_distribucion_detalle']} no fue encontrada.");
                }

                $pesoNeto = (float) ($ddt['peso_neto_cliente'] ?? 0);
                $leyHumedad = (float) $ddt['ley_humedad_cliente'];
                $pesoSeco = $pesoNeto * (1 - ($leyHumedad / 100));

                $elementoEnum = ElementoQuimicoValorizacion::tryFrom($det['elemento_quimico']) ?? ElementoQuimicoValorizacion::Oro;
                $ley = $elementoEnum === ElementoQuimicoValorizacion::Oro
                    ? (float) $ddt['ley_oro_cliente']
                    : (float) $ddt['ley_plata_cliente'];

                $inter = (float) $det['inter'];
                $desInter = (float) $det['des_inter'];
                $recuperacion = (float) $det['recuperacion'];
                $maquila = (float) $det['maquila'];
                $consumo = (float) $det['consumo'];
                $factor = (float) ($det['factor'] ?? 1.0);

                // Formula: ptn = ((inter - desInter) * ley * (rec / 100) - maquila - consumo) * factor
                $ptn = (($inter - $desInter) * $ley * ($recuperacion / 100) - $maquila - $consumo) * $factor;

                // Formula: subtotal = ptn * pesoSeco / 1000
                $subtotal = ($ptn * $pesoSeco) / 1000;

                $detallesCreados[] = ValorizacionVentaDetalle::create([
                    'id_valorizacion_venta' => $valorizacion->id,
                    'id_distribucion_detalle' => (int) $det['id_distribucion_detalle'],
                    'id_condicion_comercial' => isset($det['id_condicion_comercial']) ? (int) $det['id_condicion_comercial'] : null,
                    'id_valor_elemento_quimico' => isset($det['id_valor_elemento_quimico']) ? (int) $det['id_valor_elemento_quimico'] : null,
                    'elemento_quimico' => $elementoEnum->value,
                    'inter' => $inter,
                    'des_inter' => $desInter,
                    'recuperacion' => $recuperacion,
                    'maquila' => $maquila,
                    'consumo' => $consumo,
                    'factor' => $factor,
                    'precio_por_tonelada' => round($ptn, 2),
                    'subtotal' => round($subtotal, 2),
                    'log_cambios' => [],
                ]);
            }

            // Prender flags esta_valorizado_X del distribucion_detalle para los detalles recién creados
            self::marcarDistribucionesValorizadas($detallesCreados);

            DB::commit();

            $valDetalle = ValorizacionVentaData::get_valorizacion_by_id($valorizacion->id);

            return ApiResponse::success($valDetalle, 'Valorización de venta creada correctamente en estado Pendiente.');
        } catch (Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al crear valorización: '.$e->getMessage());
        }
    }

    /**
     * Editar una valorización existente en estado Pendiente
     *
     * @param  array<string,mixed>  $data
     * @param  \Illuminate\Http\UploadedFile[]  $archivos
     */
    public static function editar_valorizacion(int $id, array $data, array $archivos = []): array
    {
        DB::beginTransaction();
        try {
            $valorizacion = ValorizacionVentaData::find_model($id);
            if (! $valorizacion) {
                return ApiResponse::error("Valorización con ID {$id} no encontrada.");
            }

            if ($valorizacion->estado->value !== EstadoValorizacionVenta::Pendiente->value) {
                return ApiResponse::error('Solo se pueden editar valorizaciones en estado Pendiente.');
            }

            // 1. Procesar evidencias (registro de cambios a nivel cabecera se hace al final de este método)
            $rawEvidencias = $valorizacion->evidencias;
            $vAntEvidenciasRaw = is_array($rawEvidencias)
                ? $rawEvidencias
                : (is_string($rawEvidencias) ? (json_decode($rawEvidencias, true) ?? []) : []);

            $rawExistentes = $data['evidencias_existentes'] ?? null;
            $evidenciasExistentesRaw = is_array($rawExistentes)
                ? $rawExistentes
                : (is_string($rawExistentes) ? (json_decode($rawExistentes, true) ?? []) : []);

            $evidenciasNuevas = ! empty($archivos)
                ? ArchivoHelper::guardarArchivos('valorizaciones_venta', $archivos)
                : [];

            $vNueEvidencias = array_values(array_merge($evidenciasExistentesRaw, $evidenciasNuevas));

            $valorizacion->update([
                'id_planta' => (int) ($data['id_planta'] ?? $valorizacion->id_planta),
                'codigo' => $data['codigo'] ?? $valorizacion->codigo,
                'evidencias' => ! empty($vNueEvidencias) ? array_values($vNueEvidencias) : null,
                'fecha_hora_valorizacion' => $data['fecha_hora_valorizacion'] ?? null,
                'monto_penalidad' => $data['monto_penalidad'] ?? 0,
                'monto_flete' => $data['monto_flete'] ?? 0,
            ]);

            // Auditoría de cambios a nivel cabecera
            $cambiosCabecera = [];
            $oldIdPlanta = (int) $valorizacion->getOriginal('id_planta');
            $newIdPlanta = (int) ($data['id_planta'] ?? $oldIdPlanta);
            if ($oldIdPlanta !== $newIdPlanta) {
                $cambiosCabecera[] = [
                    'campo_bd' => 'id_planta',
                    'campo' => 'Planta Destino',
                    'valor_anterior' => self::get_nombre_planta($oldIdPlanta),
                    'valor_nuevo' => self::get_nombre_planta($newIdPlanta),
                ];
            }

            $oldCodigo = (string) ($valorizacion->getOriginal('codigo') ?? '');
            $newCodigo = (string) ($data['codigo'] ?? $oldCodigo);
            if ($oldCodigo !== $newCodigo) {
                $cambiosCabecera[] = [
                    'campo_bd' => 'codigo',
                    'campo' => 'Código',
                    'valor_anterior' => $oldCodigo !== '' ? $oldCodigo : '—',
                    'valor_nuevo' => $newCodigo !== '' ? $newCodigo : '—',
                ];
            }

            $oldPenalidad = (float) $valorizacion->getOriginal('monto_penalidad');
            $newPenalidad = (float) ($data['monto_penalidad'] ?? $oldPenalidad);
            if (abs($oldPenalidad - $newPenalidad) > 0.01) {
                $cambiosCabecera[] = [
                    'campo_bd' => 'monto_penalidad',
                    'campo' => 'Monto Penalidad',
                    'valor_anterior' => '$ '.number_format($oldPenalidad, 2),
                    'valor_nuevo' => '$ '.number_format($newPenalidad, 2),
                ];
            }

            $oldFlete = (float) $valorizacion->getOriginal('monto_flete');
            $newFlete = (float) ($data['monto_flete'] ?? $oldFlete);
            if (abs($oldFlete - $newFlete) > 0.01) {
                $cambiosCabecera[] = [
                    'campo_bd' => 'monto_flete',
                    'campo' => 'Monto Flete',
                    'valor_anterior' => '$ '.number_format($oldFlete, 2),
                    'valor_nuevo' => '$ '.number_format($newFlete, 2),
                ];
            }

            // Re-sincronizar detalles
            $oldDetalles = ValorizacionVentaDetalle::where('id_valorizacion_venta', $id)->get();
            $oldDetMap = [];
            foreach ($oldDetalles as $od) {
                $ddt = ValorizacionVentaData::find_distribucion_detalle_con_planta((int) $od->id_distribucion_detalle);
                // Identificador más específico para el log: combina código cliente +
                // correlativo de despacho + número de partición (cuando existe) para
                // evitar la ambigüedad entre el código cliente y el despacho correlativo.
                $partes = [];
                if (! empty($ddt['codigo_cliente'])) {
                    $partes[] = "Cód:{$ddt['codigo_cliente']}";
                }
                if (! empty($ddt['despacho_correlativo'])) {
                    $partes[] = "Despacho:{$ddt['despacho_correlativo']}";
                }
                if (! empty($ddt['lote_correlativo'])) {
                    $partes[] = "Lote:{$ddt['lote_correlativo']}";
                }
                if (! empty($ddt['blending_correlativo'])) {
                    $partes[] = "Blend:{$ddt['blending_correlativo']}";
                }
                if (! empty($ddt['numero_particion'])) {
                    $partes[] = "Part.#{$ddt['numero_particion']}";
                }
                $identificador = ! empty($partes)
                    ? implode(' · ', $partes)
                    : "Det. Distribución #{$od->id_distribucion_detalle}";
                $key = "{$od->id_distribucion_detalle}_{$od->elemento_quimico->value}";
                $oldDetMap[$key] = [
                    'identificador' => $identificador,
                    'elemento' => $od->elemento_quimico->value,
                    'inter' => (float) $od->inter,
                    'des_inter' => (float) $od->des_inter,
                    'recuperacion' => (float) $od->recuperacion,
                    'maquila' => (float) $od->maquila,
                    'consumo' => (float) $od->consumo,
                    'factor' => (float) $od->factor,
                    'precio_por_tonelada' => (float) $od->precio_por_tonelada,
                    'subtotal' => (float) $od->subtotal,
                    'log_cambios' => $od->log_cambios ?? [],
                ];
            }

            ValorizacionVentaData::delete_detalles_by_valorizacion($id);
            self::liberarDistribucionesValorizadas($id, $oldDetalles);

            $detallesCreados = [];
            foreach ($data['detalles'] as $det) {
                $ddt = ValorizacionVentaData::find_distribucion_detalle_con_planta((int) $det['id_distribucion_detalle']);
                if (! $ddt) {
                    throw new Exception("La distribucion_detalle ID {$det['id_distribucion_detalle']} no fue encontrada.");
                }

                $pesoNeto = (float) ($ddt['peso_neto_cliente'] ?? 0);
                $leyHumedad = (float) $ddt['ley_humedad_cliente'];
                $pesoSeco = $pesoNeto * (1 - ($leyHumedad / 100));

                $elementoEnum = ElementoQuimicoValorizacion::tryFrom($det['elemento_quimico']) ?? ElementoQuimicoValorizacion::Oro;
                $ley = $elementoEnum === ElementoQuimicoValorizacion::Oro
                    ? (float) $ddt['ley_oro_cliente']
                    : (float) $ddt['ley_plata_cliente'];

                $inter = (float) $det['inter'];
                $desInter = (float) $det['des_inter'];
                $recuperacion = (float) $det['recuperacion'];
                $maquila = (float) $det['maquila'];
                $consumo = (float) $det['consumo'];
                $factor = (float) ($det['factor'] ?? 1.0);

                $ptn = (($inter - $desInter) * $ley * ($recuperacion / 100) - $maquila - $consumo) * $factor;
                $subtotal = ($ptn * $pesoSeco) / 1000;

                // Auditoría independiente para el detalle (columna log_cambios SÍ existe en el detalle)
                $keyDet = "{$det['id_distribucion_detalle']}_{$elementoEnum->value}";
                $logCambiosDetalle = [];
                if (isset($oldDetMap[$keyDet])) {
                    $oldInfo = $oldDetMap[$keyDet];
                    $cambiosDet = [];

                    $fields = [
                        'inter' => ['label' => 'Internacional ($/Oz)', 'fmt' => fn ($v) => '$ '.number_format((float) $v, 2), 'new' => $inter],
                        'des_inter' => ['label' => 'Descuento Internacional ($/Oz)', 'fmt' => fn ($v) => '$ '.number_format((float) $v, 2), 'new' => $desInter],
                        'recuperacion' => ['label' => 'Recuperación (%)', 'fmt' => fn ($v) => number_format((float) $v, 2).' %', 'new' => $recuperacion],
                        'maquila' => ['label' => 'Maquila ($/TMS)', 'fmt' => fn ($v) => '$ '.number_format((float) $v, 2), 'new' => $maquila],
                        'consumo' => ['label' => 'Consumo ($/TMS)', 'fmt' => fn ($v) => '$ '.number_format((float) $v, 2), 'new' => $consumo],
                        'factor' => ['label' => 'Factor de Conversión', 'fmt' => fn ($v) => number_format((float) $v, 2), 'new' => $factor],
                    ];

                    foreach ($fields as $fieldKey => $cfg) {
                        $oldVal = (float) ($oldInfo[$fieldKey] ?? 0);
                        $newRaw = (float) $cfg['new'];
                        $diff = abs($oldVal - $newRaw);
                        if ($diff > 0.0001) {
                            $cambiosDet[] = [
                                'campo_bd' => $fieldKey,
                                'campo' => $cfg['label'],
                                'valor_anterior' => ($cfg['fmt'])($oldVal),
                                'valor_nuevo' => ($cfg['fmt'])($newRaw),
                            ];
                        }
                    }

                    $oldPtnRound = round((float) ($oldInfo['precio_por_tonelada'] ?? 0), 2);
                    $newPtnRound = round((float) $ptn, 2);
                    if (abs($oldPtnRound - $newPtnRound) > 0.01) {
                        $cambiosDet[] = [
                            'campo_bd' => 'precio_por_tonelada',
                            'campo' => 'Precio por Tonelada (PTN)',
                            'valor_anterior' => '$ '.number_format($oldPtnRound, 2),
                            'valor_nuevo' => '$ '.number_format($newPtnRound, 2),
                        ];
                    }

                    $oldSubRound = round((float) ($oldInfo['subtotal'] ?? 0), 2);
                    $newSubRound = round((float) $subtotal, 2);
                    if (abs($oldSubRound - $newSubRound) > 0.01) {
                        $cambiosDet[] = [
                            'campo_bd' => 'subtotal',
                            'campo' => 'Subtotal de Detalle',
                            'valor_anterior' => '$ '.number_format($oldSubRound, 2),
                            'valor_nuevo' => '$ '.number_format($newSubRound, 2),
                        ];
                    }

                    $logCambiosDetalle = $oldInfo['log_cambios'] ?? [];
                    if (! empty($cambiosDet)) {
                        $logCambiosDetalle[] = [
                            'fecha_hora' => now()->toDateTimeString(),
                            'update_at' => now()->toIso8601String(),
                            'id_empleado' => (int) $data['id_empleado_edicion'],
                            'accion' => 'Edición de Parámetros de Detalle',
                            'motivo' => "Detalle {$oldInfo['identificador']} ({$oldInfo['elemento']}): Modificación de parámetros",
                            'cambios' => $cambiosDet,
                        ];
                    }
                }

                $detallesCreados[] = ValorizacionVentaDetalle::create([
                    'id_valorizacion_venta' => $valorizacion->id,
                    'id_distribucion_detalle' => (int) $det['id_distribucion_detalle'],
                    'id_condicion_comercial' => isset($det['id_condicion_comercial']) ? (int) $det['id_condicion_comercial'] : null,
                    'id_valor_elemento_quimico' => isset($det['id_valor_elemento_quimico']) ? (int) $det['id_valor_elemento_quimico'] : null,
                    'elemento_quimico' => $elementoEnum->value,
                    'inter' => $inter,
                    'des_inter' => $desInter,
                    'recuperacion' => $recuperacion,
                    'maquila' => $maquila,
                    'consumo' => $consumo,
                    'factor' => $factor,
                    'precio_por_tonelada' => round($ptn, 2),
                    'subtotal' => round($subtotal, 2),
                    'log_cambios' => $logCambiosDetalle,
                ]);
            }

            // Prender flags esta_valorizado_X de distribuciones_detalle para los detalles recién creados
            self::marcarDistribucionesValorizadas($detallesCreados);

            // Persistir cabecera-level log_cambios (planta, código, penalidad, flete)
            if (! empty($cambiosCabecera)) {
                $logCabecera = $valorizacion->log_cambios ?? [];
                if (! is_array($logCabecera)) {
                    $logCabecera = json_decode((string) $logCabecera, true) ?? [];
                }
                $logCabecera[] = [
                    'fecha_hora' => now()->toDateTimeString(),
                    'update_at' => now()->toIso8601String(),
                    'id_empleado' => (int) $data['id_empleado_edicion'],
                    'accion' => 'Edición de Cabecera',
                    'motivo' => ! empty($data['motivo_edicion']) ? trim($data['motivo_edicion']) : 'Edición de Valorización',
                    'cambios' => $cambiosCabecera,
                ];
                $valorizacion->update(['log_cambios' => $logCabecera]);
            }

            DB::commit();

            $valDetalle = ValorizacionVentaData::get_valorizacion_by_id($id);

            return ApiResponse::success($valDetalle, 'Valorización actualizada correctamente.');
        } catch (Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al actualizar valorización: '.$e->getMessage());
        }
    }

    /**
     * Aprobar una valorización (enciende flags esta_valorizado_X por elemento)
     * y registra el evento en el log_cambios de la cabecera.
     */
    public static function aprobar_valorizacion(int $id, int $idEmpleadoAprobacion): array
    {
        DB::beginTransaction();
        try {
            $valorizacion = ValorizacionVentaData::find_model($id);
            if (! $valorizacion) {
                return ApiResponse::error("Valorización con ID {$id} no encontrada.");
            }

            if ($valorizacion->estado->value !== EstadoValorizacionVenta::Pendiente->value) {
                return ApiResponse::error('Solo se pueden aprobar valorizaciones que estén en estado Pendiente.');
            }

            self::marcarDistribucionesValorizadas($valorizacion->detalles);

            $logCambios = $valorizacion->log_cambios ?? [];
            if (! is_array($logCambios)) {
                $logCambios = json_decode((string) $logCambios, true) ?? [];
            }
            $logCambios[] = [
                'fecha_hora' => now()->toDateTimeString(),
                'update_at' => now()->toIso8601String(),
                'id_empleado' => $idEmpleadoAprobacion,
                'accion' => 'Aprobación de Valorización',
                'motivo' => 'Aprobación exitosa de la valorización de venta',
                'cambios' => [
                    [
                        'campo_bd' => 'estado',
                        'campo' => 'Estado',
                        'valor_anterior' => EstadoValorizacionVenta::Pendiente->value,
                        'valor_nuevo' => EstadoValorizacionVenta::Aprobado->value,
                    ],
                ],
            ];

            $valorizacion->update([
                'estado' => EstadoValorizacionVenta::Aprobado->value,
                'id_empleado_aprobacion' => $idEmpleadoAprobacion,
                'fecha_hora_aprobacion' => now(),
                'log_cambios' => $logCambios,
            ]);

            DB::commit();

            $valDetalle = ValorizacionVentaData::get_valorizacion_by_id($id);

            return ApiResponse::success($valDetalle, 'Valorización aprobada exitosamente.');
        } catch (Exception $e) {
            DB::rollBack();

            return ApiResponse::error($e->getMessage());
        }
    }

    /**
     * Anular o eliminar una valorización.
     *
     * Anulación lógica:
     *  - Cambia estado a Anulado.
     *  - Libera flags esta_valorizado_oro/plata en distribucion_detalle.
     *  - Registra el evento en log_cambios de la cabecera con motivo, empleado y
     *    timestamp (requiere ALTER TABLE para id_empleado_anulacion /
     *    fecha_hora_anulacion / motivo_anulacion / log_cambios).
     *
     * Eliminación física: borra cabecera y detalles en cascada, libera flags.
     *
     * @param  array<int,\Illuminate\Http\UploadedFile>|\Illuminate\Http\UploadedFile[]  $archivosEvidencia
     */
    public static function anular_valorizacion(
        int $id,
        int $idEmpleadoAnulacion,
        string $motivoAnulacion,
        string $tipoEliminacion = 'logica',
        array $archivosEvidencia = []
    ): array {
        DB::beginTransaction();
        try {
            $valorizacion = ValorizacionVentaData::find_model($id);
            if (! $valorizacion) {
                return ApiResponse::error("Valorización con ID {$id} no encontrada.");
            }

            if ($tipoEliminacion === 'fisica') {
                $detallesParaLiberar = $valorizacion->detalles;
                ValorizacionVentaData::delete_detalles_by_valorizacion($id);
                ValorizacionVentaData::delete_model($valorizacion);

                self::liberarDistribucionesValorizadas($id, $detallesParaLiberar);

                DB::commit();

                return ApiResponse::success(null, 'Valorización eliminada físicamente de forma permanente.');
            }

            // Eliminación Lógica
            if ($valorizacion->estado->value === EstadoValorizacionVenta::Anulado->value) {
                return ApiResponse::error('La valorización ya se encuentra en estado Anulado.');
            }

            $estadoAnterior = $valorizacion->estado->value;

            $logCambios = $valorizacion->log_cambios ?? [];
            if (! is_array($logCambios)) {
                $logCambios = json_decode((string) $logCambios, true) ?? [];
            }
            $logCambios[] = [
                'fecha_hora' => now()->toDateTimeString(),
                'update_at' => now()->toIso8601String(),
                'id_empleado' => $idEmpleadoAnulacion,
                'accion' => 'Anulación Lógica de Valorización',
                'motivo' => $motivoAnulacion,
                'cambios' => [
                    [
                        'campo_bd' => 'estado',
                        'campo' => 'Estado',
                        'valor_anterior' => $estadoAnterior,
                        'valor_nuevo' => EstadoValorizacionVenta::Anulado->value,
                    ],
                ],
            ];

            $valorizacion->update([
                'estado' => EstadoValorizacionVenta::Anulado->value,
                'id_empleado_anulacion' => $idEmpleadoAnulacion,
                'fecha_hora_anulacion' => now(),
                'motivo_anulacion' => $motivoAnulacion,
                'log_cambios' => $logCambios,
            ]);

            self::liberarDistribucionesValorizadas($id, $valorizacion->detalles);

            DB::commit();

            $valDetalle = ValorizacionVentaData::get_valorizacion_by_id($id);

            return ApiResponse::success($valDetalle, 'Valorización anulada lógicamente correctamente.');
        } catch (Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al anular valorización: '.$e->getMessage());
        }
    }

    /**
     * Marca distribuciones_detalle como valorizadas por su elemento químico (flag independiente).
     *
     * @param  iterable<\App\Models\ValorizacionVentaDetalle>  $detalles
     */
    private static function marcarDistribucionesValorizadas(iterable $detalles): void
    {
        $porElemento = [];
        foreach ($detalles as $det) {
            $elemento = $det->elemento_quimico?->value;
            if (! in_array($elemento, ['Oro', 'Plata'], true)) {
                continue;
            }
            $porElemento[$elemento][] = (int) $det->id_distribucion_detalle;
        }

        foreach ($porElemento as $elemento => $idsDetalles) {
            $idsDetalles = array_values(array_unique($idsDetalles));
            $columna = $elemento === 'Oro' ? 'esta_valorizado_oro' : 'esta_valorizado_plata';
            DB::table('distribucion_detalle')->whereIn('id', $idsDetalles)->update([$columna => 1]);
        }
    }

    /**
     * Resetea flags esta_valorizado_X de las distribuciones_detalle de una valorización,
     * siempre que no exista OTRA valorización viva (no anulada) valorizando el mismo
     * par (id_distribucion_detalle, elemento).
     *
     * @param  iterable<\App\Models\ValorizacionVentaDetalle>  $detalles
     */
    private static function liberarDistribucionesValorizadas(int $idValorizacionActual, iterable $detalles): void
    {
        $estadoAnulado = EstadoValorizacionVenta::Anulado->value;

        foreach ($detalles as $det) {
            $elemento = $det->elemento_quimico?->value;
            if (! in_array($elemento, ['Oro', 'Plata'], true)) {
                continue;
            }
            $columna = $elemento === 'Oro' ? 'esta_valorizado_oro' : 'esta_valorizado_plata';

            $existeOtra = DB::table('valorizacion_venta_detalle as vvd')
                ->join('valorizacion_venta as vv', 'vv.id', '=', 'vvd.id_valorizacion_venta')
                ->where('vvd.id_distribucion_detalle', (int) $det->id_distribucion_detalle)
                ->where('vv.estado', '!=', $estadoAnulado)
                ->where('vv.id', '!=', $idValorizacionActual)
                ->where('vvd.elemento_quimico', $elemento)
                ->exists();

            if (! $existeOtra) {
                DB::table('distribucion_detalle')
                    ->where('id', (int) $det->id_distribucion_detalle)
                    ->update([$columna => 0]);
            }
        }
    }

    private static function get_nombre_planta(int $idPlanta): string
    {
        $p = PlantaDestino::find($idPlanta);

        return $p ? $p->razon_social : "ID #{$idPlanta}";
    }
}
