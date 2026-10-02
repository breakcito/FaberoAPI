<?php

namespace App\Modules\ValorizacionVenta\Services;

use App\Models\Empresa;
use App\Models\PlantaDestino;
use App\Models\ValorizacionVenta;
use App\Models\ValorizacionVentaDetalle;
use App\Modules\ValorizacionVenta\Data\ValorizacionVentaData;
use App\Shared\Enums\_Generic\ElementoQuimicoValorizacion;
use App\Shared\Enums\_Generic\Periodo;
use App\Shared\Enums\ContabilidadVenta\EstadoComprobanteVenta;
use App\Shared\Enums\ValorizacionVenta\EstadoValorizacionVenta;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Helpers\CorrelativoHelper;
use App\Shared\Responses\ApiResponse;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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

            $idEmpresa = ! empty($data['id_empresa']) ? (int) $data['id_empresa'] : null;
            if (! $idEmpresa) {
                $fabero = Empresa::where('razon_social', 'like', '%Fabero%')->first() ?? Empresa::first();
                $idEmpresa = $fabero ? (int) $fabero->id : null;
            }

            $valorizacion = ValorizacionVenta::create([
                'id_planta' => (int) $data['id_planta'],
                'id_empresa' => $idEmpresa,
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

            // Guardar Detalles de despacho_detalle
            $detallesCreados = [];
            foreach ($data['detalles'] as $det) {
                $idDespachoDetalle = (int) ($det['id_despacho_detalle'] ?? $det['id_distribucion_detalle']);
                $dd = ValorizacionVentaData::find_despacho_detalle_con_planta($idDespachoDetalle);
                if (! $dd) {
                    throw new Exception("El item de despacho ID {$idDespachoDetalle} no fue encontrado.");
                }

                $codPreliminar = $dd['codigo_preliminar'] ?? "ID #{$idDespachoDetalle}";

                // Validaciones para poder valorizar:
                $totalDist = (int) ($dd['total_distribuciones'] ?? 0);
                $totalValidas = (int) ($dd['total_distribuciones_validas'] ?? 0);
                if ($totalDist === 0 || $totalDist !== $totalValidas) {
                    throw new Exception("El item {$codPreliminar} no puede valorizarse: no todas sus distribuciones han llegado al cliente o no tienen peso neto.");
                }

                $pesoTomado = (float) $dd['peso_tomado'];
                $pesoDistribuidoTotal = (float) ($dd['peso_distribuido_total'] ?? 0);
                if (abs($pesoDistribuidoTotal - $pesoTomado) > 0.01) {
                    throw new Exception("El item {$codPreliminar} no puede valorizarse: no ha sido distribuido en su totalidad (peso distribuido: {$pesoDistribuidoTotal} kg vs peso tomado: {$pesoTomado} kg).");
                }

                $elementoEnum = ElementoQuimicoValorizacion::tryFrom($det['elemento_quimico']) ?? ElementoQuimicoValorizacion::Oro;
                $esOro = $elementoEnum === ElementoQuimicoValorizacion::Oro;

                if ($esOro) {
                    if (empty($dd['ley_oro_final_confirmada'])) {
                        throw new Exception("La ley final de Oro no está confirmada para el item {$codPreliminar}.");
                    }
                    if (! empty($dd['esta_valorizado_oro'])) {
                        throw new Exception("El item {$codPreliminar} ya se encuentra valorizado para Oro.");
                    }
                    $ley = (float) $dd['ley_oro_final'];
                } else {
                    if (empty($dd['ley_plata_final_confirmada'])) {
                        throw new Exception("La ley final de Plata no está confirmada para el item {$codPreliminar}.");
                    }
                    if (! empty($dd['esta_valorizado_plata'])) {
                        throw new Exception("El item {$codPreliminar} ya se encuentra valorizado para Plata.");
                    }
                    $ley = (float) $dd['ley_plata_final'];
                }

                $pesoNeto = $pesoTomado;
                $leyHumedad = (float) ($dd['ley_humedad_cliente'] ?? 0);
                $pesoSeco = $pesoNeto * (1 - ($leyHumedad / 100));

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
                    'id_despacho_detalle' => $idDespachoDetalle,
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

            // Prender flags esta_valorizado_X de despacho_detalle para los detalles recién creados
            self::marcarDespachoDetallesValorizados($detallesCreados);

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
                DB::rollBack();

                return ApiResponse::error("Valorización con ID {$id} no encontrada.");
            }

            if ($valorizacion->estado->value !== EstadoValorizacionVenta::Pendiente->value) {
                DB::rollBack();

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
                'id_empresa' => ! empty($data['id_empresa']) ? (int) $data['id_empresa'] : $valorizacion->id_empresa,
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
                $idDespachoDetalle = (int) $od->id_despacho_detalle;
                $dd = ValorizacionVentaData::find_despacho_detalle_con_planta($idDespachoDetalle);
                $partes = [];
                if (! empty($dd['codigo_preliminar'])) {
                    $partes[] = "Cód:{$dd['codigo_preliminar']}";
                }
                if (! empty($dd['despacho_correlativo'])) {
                    $partes[] = "Despacho:{$dd['despacho_correlativo']}";
                }
                if (! empty($dd['lote_correlativo'])) {
                    $partes[] = "Lote:{$dd['lote_correlativo']}";
                }
                if (! empty($dd['blending_correlativo'])) {
                    $partes[] = "Blend:{$dd['blending_correlativo']}";
                }
                $identificador = ! empty($partes)
                    ? implode(' · ', $partes)
                    : "Item Despacho #{$idDespachoDetalle}";
                $key = "{$idDespachoDetalle}_{$od->elemento_quimico->value}";
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
            self::liberarDespachoDetallesValorizados($id, $oldDetalles);

            $detallesCreados = [];
            foreach ($data['detalles'] as $det) {
                $idDespachoDetalle = (int) ($det['id_despacho_detalle'] ?? $det['id_distribucion_detalle']);
                $dd = ValorizacionVentaData::find_despacho_detalle_con_planta($idDespachoDetalle);
                if (! $dd) {
                    throw new Exception("El item de despacho ID {$idDespachoDetalle} no fue encontrado.");
                }

                $codPreliminar = $dd['codigo_preliminar'] ?? "ID #{$idDespachoDetalle}";

                $totalDist = (int) ($dd['total_distribuciones'] ?? 0);
                $totalValidas = (int) ($dd['total_distribuciones_validas'] ?? 0);
                if ($totalDist === 0 || $totalDist !== $totalValidas) {
                    throw new Exception("El item {$codPreliminar} no puede valorizarse: no todas sus distribuciones han llegado al cliente o no tienen peso neto.");
                }

                $pesoTomado = (float) $dd['peso_tomado'];
                $pesoDistribuidoTotal = (float) ($dd['peso_distribuido_total'] ?? 0);
                if (abs($pesoDistribuidoTotal - $pesoTomado) > 0.01) {
                    throw new Exception("El item {$codPreliminar} no puede valorizarse: no ha sido distribuido en su totalidad (peso distribuido: {$pesoDistribuidoTotal} kg vs peso tomado: {$pesoTomado} kg).");
                }

                $elementoEnum = ElementoQuimicoValorizacion::tryFrom($det['elemento_quimico']) ?? ElementoQuimicoValorizacion::Oro;
                $esOro = $elementoEnum === ElementoQuimicoValorizacion::Oro;

                if ($esOro) {
                    if (empty($dd['ley_oro_final_confirmada'])) {
                        throw new Exception("La ley final de Oro no está confirmada para el item {$codPreliminar}.");
                    }
                    $ley = (float) $dd['ley_oro_final'];
                } else {
                    if (empty($dd['ley_plata_final_confirmada'])) {
                        throw new Exception("La ley final de Plata no está confirmada para el item {$codPreliminar}.");
                    }
                    $ley = (float) $dd['ley_plata_final'];
                }

                $pesoNeto = $pesoTomado;
                $leyHumedad = (float) ($dd['ley_humedad_cliente'] ?? 0);
                $pesoSeco = $pesoNeto * (1 - ($leyHumedad / 100));

                $inter = (float) $det['inter'];
                $desInter = (float) $det['des_inter'];
                $recuperacion = (float) $det['recuperacion'];
                $maquila = (float) $det['maquila'];
                $consumo = (float) $det['consumo'];
                $factor = (float) ($det['factor'] ?? 1.0);

                $ptn = (($inter - $desInter) * $ley * ($recuperacion / 100) - $maquila - $consumo) * $factor;
                $subtotal = ($ptn * $pesoSeco) / 1000;

                // Auditoría independiente para el detalle (columna log_cambios SÍ existe en el detalle)
                $keyDet = "{$idDespachoDetalle}_{$elementoEnum->value}";
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
                    'id_despacho_detalle' => $idDespachoDetalle,
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

            // Prender flags esta_valorizado_X de despacho_detalle para los detalles recién creados
            self::marcarDespachoDetallesValorizados($detallesCreados);

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
                DB::rollBack();

                return ApiResponse::error("Valorización con ID {$id} no encontrada.");
            }

            if ($valorizacion->estado->value !== EstadoValorizacionVenta::Pendiente->value) {
                DB::rollBack();

                return ApiResponse::error('Solo se pueden aprobar valorizaciones que estén en estado Pendiente.');
            }

            self::marcarDespachoDetallesValorizados($valorizacion->detalles);

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
                DB::rollBack();

                return ApiResponse::error("Valorización con ID {$id} no encontrada.");
            }

            $detallesIds = $valorizacion->detalles->pluck('id')->all();

            // 1. Validar que ningún detalle esté asociado a un Comprobante de Venta activo
            if (! empty($detallesIds)) {
                $comprobantesActivos = DB::table('detalle_comprobante_venta as dcv')
                    ->join('comprobante_venta as cv', 'cv.id', '=', 'dcv.id_comprobante_venta')
                    ->whereIn('dcv.id_valorizacion_venta_detalle', $detallesIds)
                    ->where('cv.estado', '!=', EstadoComprobanteVenta::Anulado->value)
                    ->select('cv.codigo_comprobante')
                    ->pluck('codigo_comprobante')
                    ->filter()
                    ->unique()
                    ->values()
                    ->toArray();

                if (! empty($comprobantesActivos)) {
                    DB::rollBack();
                    $codigos = implode(', ', $comprobantesActivos);

                    return ApiResponse::error("No se puede anular ni eliminar la valorización: está vinculada a comprobantes de venta activos ({$codigos}). Debe anular primero los comprobantes en Contabilidad de Venta.");
                }
            }

            if ($tipoEliminacion === 'fisica') {
                // 2. En eliminación física, validar que no tenga registros históricos en detalle_comprobante_venta
                if (! empty($detallesIds)) {
                    $tieneComprobantesHistoricos = DB::table('detalle_comprobante_venta')
                        ->whereIn('id_valorizacion_venta_detalle', $detallesIds)
                        ->exists();

                    if ($tieneComprobantesHistoricos) {
                        DB::rollBack();

                        return ApiResponse::error('No se puede eliminar físicamente la valorización porque tiene comprobantes de venta vinculados en su historial contable. Utilice la anulación lógica.');
                    }
                }

                // Limpiar evidencias del almacenamiento físico
                $archivosAEliminar = array_merge(
                    is_array($valorizacion->evidencias) ? $valorizacion->evidencias : [],
                    is_array($valorizacion->evidencias_anulacion) ? $valorizacion->evidencias_anulacion : []
                );
                foreach ($archivosAEliminar as $arc) {
                    $pathRelativo = is_array($arc) ? ($arc['path_relativo'] ?? null) : null;
                    if ($pathRelativo) {
                        Storage::disk('public')->delete((string) $pathRelativo);
                    }
                }

                $detallesParaLiberar = $valorizacion->detalles;
                ValorizacionVentaData::delete_detalles_by_valorizacion($id);
                ValorizacionVentaData::delete_model($valorizacion);

                self::liberarDespachoDetallesValorizados($id, $detallesParaLiberar);

                DB::commit();

                return ApiResponse::success(null, 'Valorización eliminada físicamente de forma permanente.');
            }

            // Eliminación Lógica
            if ($valorizacion->estado->value === EstadoValorizacionVenta::Anulado->value) {
                DB::rollBack();

                return ApiResponse::error('La valorización ya se encuentra en estado Anulado.');
            }

            $estadoAnterior = $valorizacion->estado->value;

            $evidenciasAnulacionGuardadas = ! empty($archivosEvidencia)
                ? ArchivoHelper::guardarArchivos('valorizaciones_venta/anulaciones', $archivosEvidencia)
                : [];

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
                    [
                        'campo_bd' => 'motivo_anulacion',
                        'campo' => 'Motivo de Anulación',
                        'valor_anterior' => null,
                        'valor_nuevo' => $motivoAnulacion,
                    ],
                ],
            ];

            $valorizacion->update([
                'estado' => EstadoValorizacionVenta::Anulado->value,
                'id_empleado_anulacion' => $idEmpleadoAnulacion,
                'fecha_hora_anulacion' => now(),
                'motivo_anulacion' => $motivoAnulacion,
                'evidencias_anulacion' => ! empty($evidenciasAnulacionGuardadas) ? array_values($evidenciasAnulacionGuardadas) : null,
                'log_cambios' => $logCambios,
            ]);

            self::liberarDespachoDetallesValorizados($id, $valorizacion->detalles);

            DB::commit();

            $valDetalle = ValorizacionVentaData::get_valorizacion_by_id($id);

            return ApiResponse::success($valDetalle, 'Valorización anulada lógicamente correctamente.');
        } catch (Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al anular valorización: '.$e->getMessage());
        }
    }

    /**
     * Marca despacho_detalle como valorizados por su elemento químico (flag independiente).
     *
     * @param  iterable<ValorizacionVentaDetalle>  $detalles
     */
    private static function marcarDespachoDetallesValorizados(iterable $detalles): void
    {
        $porElemento = [];
        foreach ($detalles as $det) {
            $elemento = $det->elemento_quimico?->value ?? (string) $det->elemento_quimico;
            if (! in_array($elemento, ['Oro', 'Plata'], true)) {
                continue;
            }
            $idDespachoDetalle = (int) ($det->id_despacho_detalle ?? 0);
            if ($idDespachoDetalle > 0) {
                $porElemento[$elemento][] = $idDespachoDetalle;
            }
        }

        foreach ($porElemento as $elemento => $idsDetalles) {
            $idsDetalles = array_values(array_unique($idsDetalles));
            $columna = $elemento === 'Oro' ? 'esta_valorizado_oro' : 'esta_valorizado_plata';
            DB::table('despacho_detalle')->whereIn('id', $idsDetalles)->update([$columna => 1]);
        }
    }

    /**
     * Resetea flags esta_valorizado_X de los despacho_detalle de una valorización,
     * siempre que no exista OTRA valorización viva (no anulada) valorizando el mismo
     * par (id_despacho_detalle, elemento).
     *
     * @param  iterable<ValorizacionVentaDetalle>  $detalles
     */
    private static function liberarDespachoDetallesValorizados(int $idValorizacionActual, iterable $detalles): void
    {
        $estadoAnulado = EstadoValorizacionVenta::Anulado->value;

        foreach ($detalles as $det) {
            $elemento = $det->elemento_quimico?->value ?? (string) $det->elemento_quimico;
            if (! in_array($elemento, ['Oro', 'Plata'], true)) {
                continue;
            }
            $idDespachoDetalle = (int) ($det->id_despacho_detalle ?? 0);
            if ($idDespachoDetalle <= 0) {
                continue;
            }

            $columna = $elemento === 'Oro' ? 'esta_valorizado_oro' : 'esta_valorizado_plata';

            $existeOtra = DB::table('valorizacion_venta_detalle as vvd')
                ->join('valorizacion_venta as vv', 'vv.id', '=', 'vvd.id_valorizacion_venta')
                ->where('vvd.id_despacho_detalle', $idDespachoDetalle)
                ->where('vv.estado', '!=', $estadoAnulado)
                ->where('vv.id', '!=', $idValorizacionActual)
                ->where('vvd.elemento_quimico', $elemento)
                ->exists();

            if (! $existeOtra) {
                DB::table('despacho_detalle')
                    ->where('id', $idDespachoDetalle)
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
