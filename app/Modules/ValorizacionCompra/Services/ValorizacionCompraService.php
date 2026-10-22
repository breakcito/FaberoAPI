<?php

namespace App\Modules\ValorizacionCompra\Services;

use App\Models\LoteGuia;
use App\Models\LoteMineral;
use App\Models\TransaccionAnticipoProveedor;
use App\Models\ValorizacionCompra;
use App\Models\ValorizacionCompraDetalle;
use App\Modules\ValorizacionCompra\Data\ValorizacionCompraData;
use App\Shared\Enums\_Generic\ElementoQuimicoValorizacion;
use App\Shared\Enums\_Generic\Periodo;
use App\Shared\Enums\ValorizacionCompra\EstadoTransaccionAnticipo;
use App\Shared\Enums\ValorizacionCompra\EstadoValorizacionCompra;
use App\Shared\Enums\ValorizacionCompra\TipoPagoValorizacionCompra;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Helpers\CorrelativoHelper;
use App\Shared\Responses\ApiResponse;
use Exception;
use Illuminate\Support\Facades\DB;

class ValorizacionCompraService
{
    /**
     * Listar valorizaciones de compra
     */
    public static function listar_valorizaciones(?int $idProveedor = null): array
    {
        $data = ValorizacionCompraData::get_valorizaciones($idProveedor);

        return ApiResponse::success($data, 'Valorizaciones obtenidas correctamente.');
    }

    /**
     * Obtener una valorización por ID
     */
    public static function obtener_valorizacion(int $id): array
    {
        $data = ValorizacionCompraData::get_valorizacion_by_id($id);
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
                tabla: 'valorizacion_compra',
                prefijo: 'VAL',
                filtros: [],
                longitudCeros: 5,
                reseteo: Periodo::Anual
            );

            $correlativoStr = (string) $correlativoRes['correlativo'];
            $numeroCorrelativo = (string) $correlativoRes['numero_correlativo'];

            // Obtener nombre de concesion para auditoria inicial
            $concesionNombre = ValorizacionCompraData::get_nombre_concesion((int) $data['id_concesion']);

            // Verificar si todos los lotes incluidos carecen de valor comercial
            $todosSinValorComercial = ! empty($data['detalles']);
            foreach ($data['detalles'] as $dCheck) {
                $lgCheck = ValorizacionCompraData::find_lote_guia_con_mineral((int) $dCheck['id_lote_guia']);
                $lCheck = $lgCheck?->loteMineral ?? $lgCheck?->particionLoteMineral?->loteMineral;
                if (! self::loteEsSinValorComercial($lCheck)) {
                    $todosSinValorComercial = false;
                    break;
                }
            }

            // Validar límite de 10 lotes físicos únicos
            $lotesUnicos = [];
            foreach ($data['detalles'] as $d) {
                $lotesUnicos[$d['id_lote_guia']] = true;
            }
            if (count($lotesUnicos) > 10) {
                throw new Exception('Solo se puede valorizar/hacer propuesta de hasta 10 lotes.');
            }

            if ($todosSinValorComercial) {
                $data['anticipos'] = [];
                $tipoPagoEnum = TipoPagoValorizacionCompra::Transferencia;
            } else {
                $tipoPagoEnum = TipoPagoValorizacionCompra::tryFrom($data['tipo_pago']) ?? TipoPagoValorizacionCompra::Transferencia;
            }

            $evidenciasGuardadas = ! empty($archivos)
                ? ArchivoHelper::guardarArchivos('valorizaciones', $archivos)
                : [];

            $valorizacion = ValorizacionCompra::create([
                'id_proveedor_minero' => $data['id_proveedor_minero'],
                'id_concesion' => $data['id_concesion'],
                'id_cuenta_bancaria' => $data['id_cuenta_bancaria'] ?? null,
                'id_cuenta_detraccion' => $data['id_cuenta_detraccion'] ?? null,
                'id_empleado_registro' => $data['id_empleado_registro'],
                'id_empleado_aprobacion' => null,
                'id_empleado_solicita_oc' => null,
                'fecha_hora_solicitud_oc' => null,
                'numero_correlativo' => $numeroCorrelativo,
                'correlativo' => $correlativoStr,
                'tipo_pago' => $tipoPagoEnum->value,
                'porcentaje_igv' => isset($data['porcentaje_igv']) ? (float) $data['porcentaje_igv'] : 18.00,
                'porcentaje_detraccion' => isset($data['porcentaje_detraccion']) ? (float) $data['porcentaje_detraccion'] : 10.00,
                'evidencias' => ! empty($evidenciasGuardadas) ? json_encode(array_values($evidenciasGuardadas)) : null,
                'log_cambios' => [],
                'fecha_hora_aprobacion' => null,
                'fecha_hora_valorizacion' => $data['fecha_hora_valorizacion'] ?? null,
                'created_at' => now(),
                'estado' => EstadoValorizacionCompra::Propuesta->value,
            ]);

            // Guardar Detalles de lotes
            $detallesCreados = [];
            foreach ($data['detalles'] as $det) {
                $loteGuia = ValorizacionCompraData::find_lote_guia_con_mineral((int) $det['id_lote_guia']);
                $lote = $loteGuia?->loteMineral
                    ?? $loteGuia?->particionLoteMineral?->loteMineral;
                if (! $loteGuia || ! $lote) {
                    throw new Exception("El lote guía ID {$det['id_lote_guia']} no fue encontrado.");
                }

                $idGrupo = ! empty($det['id_grupo_analisis']) ? (int) $det['id_grupo_analisis'] : null;
                $grupo = $idGrupo ? DB::table('grupo_analisis')->where('id', $idGrupo)->first() : null;

                $pesoNeto = (float) ($lote->peso_neto ?? 0);
                if ($grupo && $grupo->ley_humedad_cierre > 0) {
                    $leyHumedad = (float) $grupo->ley_humedad_cierre;
                } elseif (isset($det['ley_humedad']) && (float) $det['ley_humedad'] > 0) {
                    $leyHumedad = (float) $det['ley_humedad'];
                } else {
                    $leyHumedad = (float) ($lote->ley_humedad ?? 0);
                }
                $pesoSeco = $pesoNeto * (1 - ($leyHumedad / 100));

                $elementoEnum = ElementoQuimicoValorizacion::tryFrom($det['elemento_quimico']) ?? ElementoQuimicoValorizacion::Oro;
                $leyBase = $elementoEnum === ElementoQuimicoValorizacion::Oro
                    ? ($grupo ? (float) $grupo->ley_oro_cierre : (float) ($lote->ley_oro ?? 0))
                    : ($grupo ? (float) $grupo->ley_plata_cierre : (float) ($lote->ley_plata ?? 0));

                $ley = isset($det['ley']) ? (float) $det['ley'] : $leyBase;

                // Si la ley fue modificada respecto a la del grupo, auditar en log_cambios del grupo
                if ($grupo && abs($ley - $leyBase) > 0.0001 && ! empty($data['id_empleado_registro'])) {
                    $campoLey = $elementoEnum === ElementoQuimicoValorizacion::Oro ? 'ley_oro_cierre' : 'ley_plata_cierre';
                    $logGrupo = isset($grupo->log_cambios) ? (is_array($grupo->log_cambios) ? $grupo->log_cambios : (json_decode((string) $grupo->log_cambios, true) ?? [])) : [];
                    $logGrupo[] = [
                        'id_empleado' => (int) $data['id_empleado_registro'],
                        'fecha_hora' => now()->toDateTimeString(),
                        'update_at' => now()->toIso8601String(),
                        'accion' => 'Ajuste de Ley desde Propuesta de Valorización',
                        'motivo' => "Ley {$elementoEnum->value} ajustada para propuesta {$correlativoStr}: {$leyBase} -> {$ley}",
                        'cambios' => [
                            [
                                'campo_bd' => $campoLey,
                                'campo' => "Ley {$elementoEnum->value}",
                                'valor_anterior' => (string) $leyBase,
                                'valor_nuevo' => (string) $ley,
                            ],
                        ],
                    ];
                    DB::table('grupo_analisis')->where('id', $grupo->id)->update([
                        $campoLey => $ley,
                        'log_cambios' => json_encode($logGrupo),
                    ]);
                }

                $esSinValor = self::loteEsSinValorComercial($lote);

                if ($esSinValor) {
                    $inter = 0.0;
                    $desInter = 0.0;
                    $recuperacion = 0.0;
                    $maquila = 0.0;
                    $consumo = 0.0;
                    $factor = 1.0;
                    $ptn = 0.0;
                    $subtotal = 0.0;
                    $montoPenalidad = 0.0;
                    $montoFlete = 0.0;
                    $descuento = 0.0;
                    $subtotalSinDescuento = 0.0;
                    $subtotalFinal = 0.0;
                } else {
                    $inter = (float) $det['inter'];
                    $desInter = (float) $det['des_inter'];
                    $recuperacion = (float) $det['recuperacion'];
                    $maquila = (float) $det['maquila'];
                    $consumo = (float) $det['consumo'];
                    $factor = (float) ($det['factor'] ?? 1.0);

                    // Formula: ptn = ((inter - desInter) * ley * (rec / 100) - maquila - consumo) * factor
                    $ptn = (($inter - $desInter) * $ley * ($recuperacion / 100) - $maquila - $consumo) * $factor;

                    // Formula: subtotal_sin_descuento = ptn * pesoSeco / 1000
                    $subtotalSinDescuento = round(($ptn * $pesoSeco) / 1000, 2);

                    $montoPenalidad = (float) ($det['monto_penalidad'] ?? 0);
                    $montoFlete = (float) ($det['monto_flete'] ?? 0);
                    $descuento = round($montoPenalidad + $montoFlete, 2);
                    $subtotalFinal = round($subtotalSinDescuento - $descuento, 2);
                }

                $detallesCreados[] = ValorizacionCompraDetalle::create([
                    'id_valorizacion_compra' => $valorizacion->id,
                    'id_lote_guia' => $det['id_lote_guia'],
                    'id_grupo_analisis' => $idGrupo,
                    'id_condicion_comercial' => $esSinValor ? null : ($det['id_condicion_comercial'] ?? null),
                    'id_valor_elemento_quimico' => $esSinValor ? null : (isset($det['id_valor_elemento_quimico']) ? (int) $det['id_valor_elemento_quimico'] : null),
                    'elemento_quimico' => $elementoEnum->value,
                    'inter' => $inter,
                    'des_inter' => $desInter,
                    'recuperacion' => $recuperacion,
                    'maquila' => $maquila,
                    'consumo' => $consumo,
                    'factor' => $factor,
                    'precio_por_tonelada' => round($ptn, 2),
                    'monto_penalidad' => $montoPenalidad,
                    'monto_flete' => $montoFlete,
                    'descuento' => $descuento,
                    'subtotal_sin_descuento' => $subtotalSinDescuento,
                    'subtotal' => $subtotalFinal,
                    'log_cambios' => [],
                ]);

                // Activar flag en grupo_analisis
                if ($idGrupo) {
                    $campoFlag = $elementoEnum === ElementoQuimicoValorizacion::Oro ? 'tiene_propuesta_oro' : 'tiene_propuesta_plata';
                    DB::table('grupo_analisis')->where('id', $idGrupo)->update([$campoFlag => 1]);
                }
            }

            // Prender flags esta_valorizado_X del lote_mineral para los detalles recien creados.
            self::marcarLotesValorizados($detallesCreados);

            // Guardar Transacciones de Anticipo (si aplica tipo_pago anticipo o mixto)
            if (in_array($tipoPagoEnum->value, [TipoPagoValorizacionCompra::Anticipo->value, TipoPagoValorizacionCompra::Mixto->value]) && ! empty($data['anticipos'])) {
                foreach ($data['anticipos'] as $antItem) {
                    $anticipo = ValorizacionCompraData::find_anticipo_proveedor((int) $antItem['id_anticipo_proveedor']);
                    if (! $anticipo) {
                        throw new Exception("Anticipo ID {$antItem['id_anticipo_proveedor']} no encontrado.");
                    }

                    $montoRetirado = (float) $antItem['monto_retirado'];
                    $saldoActualAnticipo = (float) $anticipo->saldo_actual;
                    $saldoActualFmt = '$ '.number_format($saldoActualAnticipo, 2);

                    // Guardar snapshot del saldo actual disponible en el momento del registro
                    TransaccionAnticipoProveedor::create([
                        'id_anticipo_proveedor' => $anticipo->id,
                        'id_valorizacion_compra' => $valorizacion->id,
                        'monto_retirado' => $montoRetirado,
                        'saldo_actual' => $saldoActualAnticipo,
                        'log_cambios' => [
                            [
                                'id_empleado' => (int) $data['id_empleado_registro'],
                                'fecha_hora' => now()->toDateTimeString(),
                                'update_at' => now()->toIso8601String(),
                                'accion' => 'Asociación de Anticipo a Valorización',
                                'motivo' => "Reserva de anticipo en Valorización {$numeroCorrelativo} - Retiro: \$ ".number_format($montoRetirado, 2),
                                'cambios' => [
                                    [
                                        'campo_bd' => 'monto_retirado',
                                        'campo' => 'Monto Retirado',
                                        'valor_anterior' => '$ 0.00',
                                        'valor_nuevo' => '$ '.number_format($montoRetirado, 2),
                                    ],
                                    [
                                        'campo_bd' => 'saldo_actual',
                                        'campo' => 'Saldo Actual',
                                        'valor_anterior' => '—',
                                        'valor_nuevo' => $saldoActualFmt,
                                    ],
                                    [
                                        'campo_bd' => 'estado',
                                        'campo' => 'Estado Transacción',
                                        'valor_anterior' => '—',
                                        'valor_nuevo' => EstadoTransaccionAnticipo::Pendiente->value,
                                    ],
                                ],
                            ],
                        ],
                        'created_at' => now(),
                        'estado' => EstadoTransaccionAnticipo::Pendiente->value,
                    ]);
                }
            }

            DB::commit();

            $valDetalle = ValorizacionCompraData::get_valorizacion_by_id($valorizacion->id);

            return ApiResponse::success($valDetalle, 'Valorización creada correctamente en estado Pendiente.');
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
            $valorizacion = ValorizacionCompraData::find_model($id);
            if (! $valorizacion) {
                DB::rollBack();

                return ApiResponse::error("Valorización con ID {$id} no encontrada.");
            }

            if ($valorizacion->estado->value !== EstadoValorizacionCompra::Propuesta->value) {
                DB::rollBack();

                return ApiResponse::error('Solo se pueden editar valorizaciones en estado Propuesta. Las valorizaciones aprobadas no pueden modificarse.');
            }

            // Verificar si todos los lotes incluidos carecen de valor comercial
            $todosSinValorComercial = ! empty($data['detalles']);
            foreach ($data['detalles'] ?? [] as $dCheck) {
                $lgCheck = ValorizacionCompraData::find_lote_guia_con_mineral((int) $dCheck['id_lote_guia']);
                $lCheck = $lgCheck?->loteMineral ?? $lgCheck?->particionLoteMineral?->loteMineral;
                if (! self::loteEsSinValorComercial($lCheck)) {
                    $todosSinValorComercial = false;
                    break;
                }
            }

            // Validar límite de 10 lotes físicos únicos
            $lotesUnicos = [];
            foreach ($data['detalles'] ?? [] as $d) {
                $lotesUnicos[$d['id_lote_guia']] = true;
            }
            if (count($lotesUnicos) > 10) {
                throw new Exception('Solo se puede valorizar/hacer propuesta de hasta 10 lotes.');
            }

            if ($todosSinValorComercial) {
                $data['anticipos'] = [];
                $tipoPagoEnum = TipoPagoValorizacionCompra::Transferencia;
            } else {
                $tipoPagoEnum = TipoPagoValorizacionCompra::tryFrom($data['tipo_pago']) ?? TipoPagoValorizacionCompra::Transferencia;
            }

            $cambios = [];

            // 1. Auditoría de cambios en cabecera
            if ($valorizacion->id_concesion != $data['id_concesion']) {
                $cambios[] = [
                    'campo_bd' => 'id_concesion',
                    'campo' => 'Concesión',
                    'valor_anterior' => ValorizacionCompraData::get_nombre_concesion((int) $valorizacion->id_concesion),
                    'valor_nuevo' => ValorizacionCompraData::get_nombre_concesion((int) $data['id_concesion']),
                ];
            }

            if ($valorizacion->id_cuenta_bancaria != ($data['id_cuenta_bancaria'] ?? null)) {
                $cambios[] = [
                    'campo_bd' => 'id_cuenta_bancaria',
                    'campo' => 'Cuenta Bancaria',
                    'valor_anterior' => ValorizacionCompraData::get_etiqueta_cuenta($valorizacion->id_cuenta_bancaria),
                    'valor_nuevo' => ValorizacionCompraData::get_etiqueta_cuenta($data['id_cuenta_bancaria'] ?? null),
                ];
            }

            if ($valorizacion->id_cuenta_detraccion != ($data['id_cuenta_detraccion'] ?? null)) {
                $cambios[] = [
                    'campo_bd' => 'id_cuenta_detraccion',
                    'campo' => 'Cuenta Detracción',
                    'valor_anterior' => ValorizacionCompraData::get_etiqueta_cuenta($valorizacion->id_cuenta_detraccion),
                    'valor_nuevo' => ValorizacionCompraData::get_etiqueta_cuenta($data['id_cuenta_detraccion'] ?? null),
                ];
            }

            if ($valorizacion->tipo_pago->value !== $tipoPagoEnum->value) {
                $cambios[] = [
                    'campo_bd' => 'tipo_pago',
                    'campo' => 'Tipo de Pago',
                    'valor_anterior' => strtoupper($valorizacion->tipo_pago->value),
                    'valor_nuevo' => strtoupper($tipoPagoEnum->value),
                ];
            }

            // 2. Auditoría de cambios en detalles (lotes de mineral)
            $resDetallesAud = self::procesarAuditoriaDetallesEdicion($id, $data['detalles'] ?? []);
            $cambios = array_merge($cambios, $resDetallesAud['cambios']);
            $newTotalSubtotal = $resDetallesAud['new_total_subtotal'];
            $oldDetMap = $resDetallesAud['old_det_map'];

            // 3. Auditoría y procesamiento de evidencias (existentes conservadas + nuevas subidas)
            $rawEvidencias = $valorizacion->evidencias;
            $vAntEvidenciasRaw = is_array($rawEvidencias)
                ? $rawEvidencias
                : (is_string($rawEvidencias) ? (json_decode($rawEvidencias, true) ?? []) : []);

            $rawExistentes = $data['evidencias_existentes'] ?? null;
            $evidenciasExistentesRaw = is_array($rawExistentes)
                ? $rawExistentes
                : (is_string($rawExistentes) ? (json_decode($rawExistentes, true) ?? []) : []);

            $evidenciasNuevas = ! empty($archivos)
                ? ArchivoHelper::guardarArchivos('valorizaciones', $archivos)
                : [];

            $vNueEvidencias = array_values(array_merge($evidenciasExistentesRaw, $evidenciasNuevas));

            $getNombreLimpio = function ($item) {
                if (is_array($item)) {
                    $nombre = $item['nombre_original'] ?? '';
                    $ext = $item['extension'] ?? '';
                    if (! empty($nombre) && ! empty($ext)) {
                        return "{$nombre}.{$ext}";
                    }
                    $p = $item['path_relativo'] ?? $item['path'] ?? $item['url'] ?? '';

                    return ! empty($p) ? basename($p) : '—';
                }
                if (is_string($item) && ! empty($item)) {
                    return basename($item);
                }

                return '—';
            };

            $nombresAnt = array_values(array_filter(array_map($getNombreLimpio, $vAntEvidenciasRaw)));
            $nombresNue = array_values(array_filter(array_map($getNombreLimpio, $vNueEvidencias)));

            $oldCopy = $nombresAnt;
            $newCopy = $nombresNue;
            sort($oldCopy);
            sort($newCopy);

            if ($oldCopy !== $newCopy) {
                $cambios[] = [
                    'campo_bd' => 'evidencias',
                    'campo' => 'Evidencias',
                    'valor_anterior' => ! empty($nombresAnt) ? implode(', ', $nombresAnt) : '—',
                    'valor_nuevo' => ! empty($nombresNue) ? implode(', ', $nombresNue) : '—',
                ];
            }

            $logCambios = $valorizacion->log_cambios ?? [];
            if (! empty($cambios)) {
                $motivoHeader = ! empty($data['motivo_edicion']) ? trim($data['motivo_edicion']) : 'Edición de Valorización de Compra';
                $logCambios[] = [
                    'fecha_hora' => now()->toDateTimeString(),
                    'update_at' => now()->toIso8601String(),
                    'id_empleado' => (int) $data['id_empleado_edicion'],
                    'accion' => 'Edición de Valorización',
                    'motivo' => $motivoHeader,
                    'cambios' => $cambios,
                ];
            }

            $valorizacion->update([
                'id_concesion' => $data['id_concesion'],
                'id_cuenta_bancaria' => $data['id_cuenta_bancaria'] ?? null,
                'id_cuenta_detraccion' => $data['id_cuenta_detraccion'] ?? null,
                'tipo_pago' => $tipoPagoEnum->value,
                'porcentaje_igv' => isset($data['porcentaje_igv']) ? (float) $data['porcentaje_igv'] : ($valorizacion->porcentaje_igv ?? 18.00),
                'porcentaje_detraccion' => isset($data['porcentaje_detraccion']) ? (float) $data['porcentaje_detraccion'] : ($valorizacion->porcentaje_detraccion ?? 10.00),
                'evidencias' => ! empty($vNueEvidencias) ? array_values($vNueEvidencias) : null,
                'log_cambios' => $logCambios,
                'total_subtotal' => $newTotalSubtotal,
                'fecha_hora_valorizacion' => $data['fecha_hora_valorizacion'] ?? null,
            ]);

            // Re-sincronizar detalles
            // 1) Liberar flags de los detalles que se van a eliminar (si no quedan otras valorizaciones vivas).
            $oldDetalles = ValorizacionCompraDetalle::where('id_valorizacion_compra', $id)->get();
            foreach ($oldDetalles as $oldDet) {
                if ($oldDet->id_grupo_analisis) {
                    $flag = $oldDet->elemento_quimico?->value === 'Oro' ? 'tiene_propuesta_oro' : 'tiene_propuesta_plata';
                    DB::table('grupo_analisis')->where('id', $oldDet->id_grupo_analisis)->update([$flag => 0]);
                }
            }
            ValorizacionCompraData::delete_detalles_by_valorizacion($id);
            self::liberarLotesValorizados($id, $oldDetalles);

            $detallesCreados = [];
            foreach ($data['detalles'] as $det) {
                $loteGuia = ValorizacionCompraData::find_lote_guia_con_mineral((int) $det['id_lote_guia']);
                $lote = $loteGuia?->loteMineral
                    ?? $loteGuia?->particionLoteMineral?->loteMineral;
                if (! $loteGuia || ! $lote) {
                    throw new Exception("El lote guía ID {$det['id_lote_guia']} no fue encontrado.");
                }

                $idGrupo = ! empty($det['id_grupo_analisis']) ? (int) $det['id_grupo_analisis'] : null;
                $grupo = $idGrupo ? DB::table('grupo_analisis')->where('id', $idGrupo)->first() : null;

                $pesoNeto = (float) ($lote->peso_neto ?? 0);
                if ($grupo && $grupo->ley_humedad_cierre > 0) {
                    $leyHumedad = (float) $grupo->ley_humedad_cierre;
                } elseif (isset($det['ley_humedad']) && (float) $det['ley_humedad'] > 0) {
                    $leyHumedad = (float) $det['ley_humedad'];
                } else {
                    $leyHumedad = (float) ($lote->ley_humedad ?? 0);
                }
                $pesoSeco = $pesoNeto * (1 - ($leyHumedad / 100));

                $elementoEnum = ElementoQuimicoValorizacion::tryFrom($det['elemento_quimico']) ?? ElementoQuimicoValorizacion::Oro;
                $leyBase = $elementoEnum === ElementoQuimicoValorizacion::Oro
                    ? ($grupo ? (float) $grupo->ley_oro_cierre : (float) ($lote->ley_oro ?? 0))
                    : ($grupo ? (float) $grupo->ley_plata_cierre : (float) ($lote->ley_plata ?? 0));

                $ley = isset($det['ley']) ? (float) $det['ley'] : $leyBase;

                // Si la ley fue modificada respecto a la del grupo, auditar en log_cambios del grupo
                if ($grupo && abs($ley - $leyBase) > 0.0001 && ! empty($data['id_empleado_edicion'])) {
                    $campoLey = $elementoEnum === ElementoQuimicoValorizacion::Oro ? 'ley_oro_cierre' : 'ley_plata_cierre';
                    $logGrupo = isset($grupo->log_cambios) ? (is_array($grupo->log_cambios) ? $grupo->log_cambios : (json_decode((string) $grupo->log_cambios, true) ?? [])) : [];
                    $logGrupo[] = [
                        'id_empleado' => (int) $data['id_empleado_edicion'],
                        'fecha_hora' => now()->toDateTimeString(),
                        'update_at' => now()->toIso8601String(),
                        'accion' => 'Ajuste de Ley desde Propuesta de Valorización (Edición)',
                        'motivo' => "Ley {$elementoEnum->value} ajustada para propuesta {$valorizacion->correlativo}: {$leyBase} -> {$ley}",
                        'cambios' => [
                            [
                                'campo_bd' => $campoLey,
                                'campo' => "Ley {$elementoEnum->value}",
                                'valor_anterior' => (string) $leyBase,
                                'valor_nuevo' => (string) $ley,
                            ],
                        ],
                    ];
                    DB::table('grupo_analisis')->where('id', $grupo->id)->update([
                        $campoLey => $ley,
                        'log_cambios' => json_encode($logGrupo),
                    ]);
                }

                $esSinValor = self::loteEsSinValorComercial($lote);

                if ($esSinValor) {
                    $inter = 0.0;
                    $desInter = 0.0;
                    $recuperacion = 0.0;
                    $maquila = 0.0;
                    $consumo = 0.0;
                    $factor = 1.0;
                    $ptn = 0.0;
                    $subtotal = 0.0;
                } else {
                    $inter = (float) $det['inter'];
                    $desInter = (float) $det['des_inter'];
                    $recuperacion = (float) $det['recuperacion'];
                    $maquila = (float) $det['maquila'];
                    $consumo = (float) $det['consumo'];
                    $factor = (float) ($det['factor'] ?? 1.0);

                    $ptn = (($inter - $desInter) * $ley * ($recuperacion / 100) - $maquila - $consumo) * $factor;
                    $subtotal = ($ptn * $pesoSeco) / 1000;
                }

                // Auditoría independiente para el detalle del lote
                $keyDet = "{$det['id_lote_guia']}_{$elementoEnum->value}";
                $logCambiosDetalle = [];
                if (isset($oldDetMap[$keyDet])) {
                    $oldInfo = $oldDetMap[$keyDet];
                    $cambiosDet = [];

                    $oldInterRound = round((float) ($oldInfo['inter'] ?? 0), 4);
                    $newInterRound = round((float) $inter, 4);
                    if (abs($oldInterRound - $newInterRound) > 0.0001) {
                        $cambiosDet[] = [
                            'campo_bd' => 'inter',
                            'campo' => 'Internacional ($/Oz)',
                            'valor_anterior' => '$ '.number_format($oldInterRound, 2),
                            'valor_nuevo' => '$ '.number_format($newInterRound, 2),
                        ];
                    }

                    $oldDesInterRound = round((float) ($oldInfo['des_inter'] ?? 0), 4);
                    $newDesInterRound = round((float) $desInter, 4);
                    if (abs($oldDesInterRound - $newDesInterRound) > 0.0001) {
                        $cambiosDet[] = [
                            'campo_bd' => 'des_inter',
                            'campo' => 'Descuento Internacional ($/Oz)',
                            'valor_anterior' => '$ '.number_format($oldDesInterRound, 2),
                            'valor_nuevo' => '$ '.number_format($newDesInterRound, 2),
                        ];
                    }

                    $oldRecRound = round((float) ($oldInfo['recuperacion'] ?? 0), 4);
                    $newRecRound = round((float) $recuperacion, 4);
                    if (abs($oldRecRound - $newRecRound) > 0.0001) {
                        $cambiosDet[] = [
                            'campo_bd' => 'recuperacion',
                            'campo' => 'Recuperación (%)',
                            'valor_anterior' => number_format($oldRecRound, 2).' %',
                            'valor_nuevo' => number_format($newRecRound, 2).' %',
                        ];
                    }

                    $oldMaqRound = round((float) ($oldInfo['maquila'] ?? 0), 4);
                    $newMaqRound = round((float) $maquila, 4);
                    if (abs($oldMaqRound - $newMaqRound) > 0.0001) {
                        $cambiosDet[] = [
                            'campo_bd' => 'maquila',
                            'campo' => 'Maquila ($/TMS)',
                            'valor_anterior' => '$ '.number_format($oldMaqRound, 2),
                            'valor_nuevo' => '$ '.number_format($newMaqRound, 2),
                        ];
                    }

                    $oldConsRound = round((float) ($oldInfo['consumo'] ?? 0), 4);
                    $newConsRound = round((float) $consumo, 4);
                    if (abs($oldConsRound - $newConsRound) > 0.0001) {
                        $cambiosDet[] = [
                            'campo_bd' => 'consumo',
                            'campo' => 'Consumo ($/TMS)',
                            'valor_anterior' => '$ '.number_format($oldConsRound, 2),
                            'valor_nuevo' => '$ '.number_format($newConsRound, 2),
                        ];
                    }

                    $oldFactRound = round((float) ($oldInfo['factor'] ?? 1.0), 4);
                    $newFactRound = round((float) $factor, 4);
                    if (abs($oldFactRound - $newFactRound) > 0.0001) {
                        $cambiosDet[] = [
                            'campo_bd' => 'factor',
                            'campo' => 'Factor de Conversión',
                            'valor_anterior' => number_format($oldFactRound, 2),
                            'valor_nuevo' => number_format($newFactRound, 2),
                        ];
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
                            'campo' => 'Subtotal de Lote',
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
                            'accion' => 'Edición de Parámetros de Lote',
                            'motivo' => "Lote {$oldInfo['correlativo']} ({$oldInfo['elemento']}): Modificación de parámetros",
                            'cambios' => $cambiosDet,
                        ];
                    }
                }

                $montoPenalidad = (float) ($det['monto_penalidad'] ?? 0);
                $montoFlete = (float) ($det['monto_flete'] ?? 0);
                $descuento = round($montoPenalidad + $montoFlete, 2);
                $subtotalSinDescuento = round($subtotal, 2);
                $subtotalFinal = round($subtotalSinDescuento - $descuento, 2);

                $detallesCreados[] = ValorizacionCompraDetalle::create([
                    'id_valorizacion_compra' => $valorizacion->id,
                    'id_lote_guia' => $det['id_lote_guia'],
                    'id_grupo_analisis' => $idGrupo,
                    'id_condicion_comercial' => $esSinValor ? null : ($det['id_condicion_comercial'] ?? null),
                    'id_valor_elemento_quimico' => $esSinValor ? null : (isset($det['id_valor_elemento_quimico']) ? (int) $det['id_valor_elemento_quimico'] : null),
                    'elemento_quimico' => $elementoEnum->value,
                    'inter' => $inter,
                    'des_inter' => $desInter,
                    'recuperacion' => $recuperacion,
                    'maquila' => $maquila,
                    'consumo' => $consumo,
                    'factor' => $factor,
                    'precio_por_tonelada' => round($ptn, 2),
                    'monto_penalidad' => $montoPenalidad,
                    'monto_flete' => $montoFlete,
                    'descuento' => $descuento,
                    'subtotal_sin_descuento' => $subtotalSinDescuento,
                    'subtotal' => $subtotalFinal,
                    'log_cambios' => $logCambiosDetalle,
                ]);

                // Activar flag en grupo_analisis
                if ($idGrupo) {
                    $campoFlag = $elementoEnum === ElementoQuimicoValorizacion::Oro ? 'tiene_propuesta_oro' : 'tiene_propuesta_plata';
                    DB::table('grupo_analisis')->where('id', $idGrupo)->update([$campoFlag => 1]);
                }
            }

            // Prender flags esta_valorizado_X del lote_mineral para los detalles recien creados
            // (los eliminados ya se liberaron arriba antes del delete).
            self::marcarLotesValorizados($detallesCreados);

            // Re-sincronizar transacciones de anticipo (UPDATE en sitio preservando historial de auditoria)
            $transaccionesExistentes = ValorizacionCompraData::get_transacciones_by_valorizacion($id);
            $transaccionesPorIdAnticipo = [];
            foreach ($transaccionesExistentes as $t) {
                $transaccionesPorIdAnticipo[$t->id_anticipo_proveedor] = $t;
            }

            $anticiposRecibidos = [];
            $usaAnticipos = in_array($tipoPagoEnum->value, [TipoPagoValorizacionCompra::Anticipo->value, TipoPagoValorizacionCompra::Mixto->value]) && ! empty($data['anticipos']);

            if ($usaAnticipos) {
                $idsAnticiposRecibidos = [];
                foreach ($data['anticipos'] as $antItem) {
                    $anticipo = ValorizacionCompraData::find_anticipo_proveedor((int) $antItem['id_anticipo_proveedor']);
                    if (! $anticipo) {
                        throw new Exception("Anticipo ID {$antItem['id_anticipo_proveedor']} no encontrado.");
                    }
                    $idsAnticiposRecibidos[$anticipo->id] = (float) $antItem['monto_retirado'];
                }

                $idEmpleadoEdicion = (int) $data['id_empleado_edicion'];
                $now = now();
                $nowIso = $now->toIso8601String();

                // 1. Procesar transacciones eliminadas: registrar entrada de auditoria antes de borrar
                foreach ($transaccionesExistentes as $transaccionExistente) {
                    if (! array_key_exists($transaccionExistente->id_anticipo_proveedor, $idsAnticiposRecibidos)) {
                        $logExistente = $transaccionExistente->log_cambios ?? [];
                        if (! is_array($logExistente)) {
                            $logExistente = json_decode((string) $logExistente, true) ?? [];
                        }
                        $logExistente[] = [
                            'id_empleado' => $idEmpleadoEdicion,
                            'fecha_hora' => $now->toDateTimeString(),
                            'update_at' => $nowIso,
                            'accion' => 'Eliminación de Asociación',
                            'motivo' => "Anticipo removido de la Valorización {$valorizacion->numero_correlativo}",
                            'cambios' => [
                                [
                                    'campo_bd' => 'id_valorizacion_compra',
                                    'campo' => 'Asociación con Valorización',
                                    'valor_anterior' => $valorizacion->numero_correlativo,
                                    'valor_nuevo' => '—',
                                ],
                            ],
                        ];
                        $transaccionExistente->update(['log_cambios' => $logExistente]);
                        $transaccionExistente->delete();
                    }
                }

                // 2. Procesar transacciones conservadas y nuevas
                foreach ($idsAnticiposRecibidos as $idAnticipoRecibido => $montoRetiradoNuevo) {
                    $anticipo = ValorizacionCompraData::find_anticipo_proveedor((int) $idAnticipoRecibido);
                    $transaccionPrevia = $transaccionesPorIdAnticipo[$idAnticipoRecibido] ?? null;

                    if ($transaccionPrevia) {
                        // UPDATE en sitio: preservar log_cambios existente, agregar entrada de edición si cambió el monto
                        $logExistente = $transaccionPrevia->log_cambios ?? [];
                        if (! is_array($logExistente)) {
                            $logExistente = json_decode((string) $logExistente, true) ?? [];
                        }

                        $cambiosEdicion = [];
                        $montoAnterior = (float) $transaccionPrevia->monto_retirado;
                        if (abs($montoAnterior - $montoRetiradoNuevo) > 0.0001) {
                            $cambiosEdicion[] = [
                                'campo_bd' => 'monto_retirado',
                                'campo' => 'Monto Retirado',
                                'valor_anterior' => '$ '.number_format($montoAnterior, 2),
                                'valor_nuevo' => '$ '.number_format($montoRetiradoNuevo, 2),
                            ];
                        }

                    $saldoActualAnticipo = (float) $anticipo->saldo_actual;
                    $saldoActualFmt = '$ '.number_format($saldoActualAnticipo, 2);
                    $montoCambio = abs($montoAnterior - $montoRetiradoNuevo) > 0.0001;
                    $snapshotCambio = abs((float) $transaccionPrevia->saldo_actual - $saldoActualAnticipo) > 0.0001;
                    if ($montoCambio && $snapshotCambio) {
                        $cambiosEdicion[] = [
                            'campo_bd' => 'saldo_actual',
                            'campo' => 'Saldo Actual',
                            'valor_anterior' => '$ '.number_format((float) $transaccionPrevia->saldo_actual, 2),
                            'valor_nuevo' => $saldoActualFmt,
                        ];
                    }

                        if (! empty($cambiosEdicion)) {
                            $logExistente[] = [
                                'id_empleado' => $idEmpleadoEdicion,
                                'fecha_hora' => $now->toDateTimeString(),
                                'update_at' => $nowIso,
                                'accion' => 'Edición de Transacción de Anticipo',
                                'motivo' => "Edición de monto en Valorización {$valorizacion->numero_correlativo}",
                                'cambios' => $cambiosEdicion,
                            ];
                        }

                        $transaccionPrevia->update([
                            'monto_retirado' => $montoRetiradoNuevo,
                            'saldo_actual' => $saldoActualAnticipo,
                            'log_cambios' => $logExistente,
                        ]);
                    } else {
                        // INSERT: nuevo anticipo en la valorización
                        $saldoActualAnticipo = (float) $anticipo->saldo_actual;
                        $saldoActualFmt = '$ '.number_format($saldoActualAnticipo, 2);

                        TransaccionAnticipoProveedor::create([
                            'id_anticipo_proveedor' => $anticipo->id,
                            'id_valorizacion_compra' => $valorizacion->id,
                            'monto_retirado' => $montoRetiradoNuevo,
                            'saldo_actual' => $saldoActualAnticipo,
                            'log_cambios' => [
                                [
                                    'id_empleado' => $idEmpleadoEdicion,
                                    'fecha_hora' => $now->toDateTimeString(),
                                    'update_at' => $nowIso,
                                    'accion' => 'Asociación de Anticipo a Valorización',
                                    'motivo' => "Asociación agregada en edición de Valorización {$valorizacion->numero_correlativo} - Retiro: \$ ".number_format($montoRetiradoNuevo, 2),
                                    'cambios' => [
                                        [
                                            'campo_bd' => 'monto_retirado',
                                            'campo' => 'Monto Retirado',
                                            'valor_anterior' => '$ 0.00',
                                            'valor_nuevo' => '$ '.number_format($montoRetiradoNuevo, 2),
                                        ],
                                        [
                                            'campo_bd' => 'saldo_actual',
                                            'campo' => 'Saldo Actual',
                                            'valor_anterior' => '—',
                                            'valor_nuevo' => $saldoActualFmt,
                                        ],
                                        [
                                            'campo_bd' => 'estado',
                                            'campo' => 'Estado Transacción',
                                            'valor_anterior' => '—',
                                            'valor_nuevo' => EstadoTransaccionAnticipo::Pendiente->value,
                                        ],
                                    ],
                                ],
                            ],
                            'created_at' => $now,
                            'estado' => EstadoTransaccionAnticipo::Pendiente->value,
                        ]);
                    }

                    $anticiposRecibidos[$idAnticipoRecibido] = $montoRetiradoNuevo;
                }
            } else {
                // Ya no usa anticipos: registrar entrada de auditoria antes de borrar
                foreach ($transaccionesExistentes as $transaccionExistente) {
                    $logExistente = $transaccionExistente->log_cambios ?? [];
                    if (! is_array($logExistente)) {
                        $logExistente = json_decode((string) $logExistente, true) ?? [];
                    }
                    $logExistente[] = [
                        'id_empleado' => (int) $data['id_empleado_edicion'],
                        'fecha_hora' => now()->toDateTimeString(),
                        'update_at' => now()->toIso8601String(),
                        'accion' => 'Eliminación de Asociación',
                        'motivo' => "Anticipo removido: tipo de pago cambiado en Valorización {$valorizacion->numero_correlativo}",
                        'cambios' => [
                            [
                                'campo_bd' => 'id_valorizacion_compra',
                                'campo' => 'Asociación con Valorización',
                                'valor_anterior' => $valorizacion->numero_correlativo,
                                'valor_nuevo' => '—',
                            ],
                        ],
                    ];
                    $transaccionExistente->update(['log_cambios' => $logExistente]);
                    $transaccionExistente->delete();
                }
            }

            DB::commit();

            $valDetalle = ValorizacionCompraData::get_valorizacion_by_id($id);

            return ApiResponse::success($valDetalle, 'Valorización actualizada correctamente.');
        } catch (Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al actualizar valorización: '.$e->getMessage());
        }
    }

    /**
     * Aprobar una valorización (convierte propuesta en valorización oficial asignando cuentas y anticipos)
     *
     * @param  array<string,mixed>  $data
     */
    public static function aprobar_valorizacion(int $id, int $idEmpleadoAprobacion, array $data = []): array
    {
        DB::beginTransaction();
        try {
            $valorizacion = ValorizacionCompraData::find_model($id);
            if (! $valorizacion) {
                DB::rollBack();

                return ApiResponse::error("Valorización con ID {$id} no encontrada.");
            }

            if ($valorizacion->estado->value !== EstadoValorizacionCompra::Propuesta->value) {
                DB::rollBack();

                return ApiResponse::error('Solo se pueden aprobar valorizaciones que estén en estado Propuesta.');
            }

            $idCuentaBancaria = ! empty($data['id_cuenta_bancaria']) ? (int) $data['id_cuenta_bancaria'] : $valorizacion->id_cuenta_bancaria;
            $idCuentaDetraccion = ! empty($data['id_cuenta_detraccion']) ? (int) $data['id_cuenta_detraccion'] : $valorizacion->id_cuenta_detraccion;
            $tipoPagoStr = ! empty($data['tipo_pago']) ? $data['tipo_pago'] : ($valorizacion->tipo_pago?->value ?? TipoPagoValorizacionCompra::Transferencia->value);

            if (empty($idCuentaBancaria)) {
                throw new Exception('Debe asignar una cuenta bancaria antes de aprobar la valorización.');
            }
            if (empty($idCuentaDetraccion)) {
                throw new Exception('Debe asignar una cuenta de detracción antes de aprobar la valorización.');
            }

            // Si vienen anticipos en $data, sincronizar transacciones
            if (! empty($data['anticipos'])) {
                ValorizacionCompraData::delete_transacciones_by_valorizacion($id);
                foreach ($data['anticipos'] as $antItem) {
                    $anticipo = ValorizacionCompraData::find_anticipo_proveedor((int) $antItem['id_anticipo_proveedor']);
                    if (! $anticipo) {
                        throw new Exception("Anticipo ID {$antItem['id_anticipo_proveedor']} no encontrado.");
                    }
                    $montoRetirado = (float) $antItem['monto_retirado'];
                    TransaccionAnticipoProveedor::create([
                        'id_anticipo_proveedor' => $anticipo->id,
                        'id_valorizacion_compra' => $valorizacion->id,
                        'monto_retirado' => $montoRetirado,
                        'saldo_actual' => (float) $anticipo->saldo_actual,
                        'log_cambios' => [],
                        'created_at' => now(),
                        'estado' => EstadoTransaccionAnticipo::Pendiente->value,
                    ]);
                }
                $valorizacion->load('transaccionesAnticipo');
            }

            // Procesar cada transacción de anticipo
            foreach ($valorizacion->transaccionesAnticipo as $transaccion) {
                $anticipo = ValorizacionCompraData::find_anticipo_proveedor((int) $transaccion->id_anticipo_proveedor);
                if (! $anticipo) {
                    throw new Exception("El anticipo ID {$transaccion->id_anticipo_proveedor} no existe.");
                }

                $montoRetirado = (float) $transaccion->monto_retirado;
                $saldoDisponible = (float) $anticipo->saldo_actual;
                $estadoAnteriorAnticipo = $anticipo->estado;
                $facturaStr = $anticipo->serie_factura.'-'.$anticipo->numero_factura;

                // Validación estricta: el monto a retirar NO debe superar el saldo actual disponible
                if ($montoRetirado > $saldoDisponible + 0.0001) {
                    $saldoDispFmt = number_format($saldoDisponible, 2);
                    $montoRetFmt = number_format($montoRetirado, 2);
                    throw new Exception("El anticipo Factura {$facturaStr} solo cuenta con un saldo de \${$saldoDispFmt}, pero la valorización requiere \${$montoRetFmt}. Por favor edite la valorización para ajustar el anticipo.");
                }

                // Descontar saldo del anticipo
                $nuevoSaldoAnticipo = round($saldoDisponible - $montoRetirado, 3);
                $nuevoEstadoAnticipo = $nuevoSaldoAnticipo <= 0
                    ? \App\Shared\Enums\_Generic\EstadoAnticipoProveedor::SinSaldo->value
                    : \App\Shared\Enums\_Generic\EstadoAnticipoProveedor::ConSaldo->value;

                $cambiosHeader = [
                    [
                        'campo_bd' => 'saldo_actual',
                        'campo' => 'Saldo Actual',
                        'valor_anterior' => '$ '.number_format($saldoDisponible, 2),
                        'valor_nuevo' => '$ '.number_format($nuevoSaldoAnticipo, 2),
                    ],
                ];

                if ($estadoAnteriorAnticipo !== $nuevoEstadoAnticipo) {
                    $cambiosHeader[] = [
                        'campo_bd' => 'estado',
                        'campo' => 'Estado',
                        'valor_anterior' => $estadoAnteriorAnticipo,
                        'valor_nuevo' => $nuevoEstadoAnticipo,
                    ];
                }

                $logHeaderAnticipo = $anticipo->log_cambios ?? [];
                if (! is_array($logHeaderAnticipo)) {
                    $logHeaderAnticipo = json_decode((string) $logHeaderAnticipo, true) ?? [];
                }
                $logHeaderAnticipo[] = [
                    'id_empleado' => $idEmpleadoAprobacion,
                    'fecha_hora' => now()->toDateTimeString(),
                    'update_at' => now()->toIso8601String(),
                    'accion' => 'Descuento de Saldo por Valorización Aprobada',
                    'motivo' => "Uso de Anticipo en Valorización {$valorizacion->numero_correlativo} - Retiro: \$ ".number_format($montoRetirado, 2),
                    'cambios' => $cambiosHeader,
                ];

                $anticipo->update([
                    'saldo_actual' => $nuevoSaldoAnticipo,
                    'estado' => $nuevoEstadoAnticipo,
                    'log_cambios' => $logHeaderAnticipo,
                ]);

                // Actualizar la transacción a Aprobado con log en formato campo-a-campo
                $logTrans = $transaccion->log_cambios ?? [];
                if (! is_array($logTrans)) {
                    $logTrans = json_decode((string) $logTrans, true) ?? [];
                }
                $logTrans[] = [
                    'id_empleado' => $idEmpleadoAprobacion,
                    'fecha_hora' => now()->toDateTimeString(),
                    'update_at' => now()->toIso8601String(),
                    'accion' => 'Aprobación de Transacción de Anticipo',
                    'motivo' => "Uso de Anticipo en Valorización {$valorizacion->numero_correlativo} - Retiro: \$ ".number_format($montoRetirado, 2),
                    'cambios' => [
                        [
                            'campo_bd' => 'estado',
                            'campo' => 'Estado Transacción',
                            'valor_anterior' => EstadoTransaccionAnticipo::Pendiente->value,
                            'valor_nuevo' => EstadoTransaccionAnticipo::Aprobado->value,
                        ],
                    ],
                ];

                $transaccion->update([
                    'saldo_actual' => $saldoDisponible,
                    'estado' => EstadoTransaccionAnticipo::Aprobado->value,
                    'log_cambios' => $logTrans,
                ]);
            }

            $logCambios = $valorizacion->log_cambios ?? [];
            $logCambios[] = [
                'fecha_hora' => now()->toDateTimeString(),
                'update_at' => now()->toIso8601String(),
                'id_empleado' => $idEmpleadoAprobacion,
                'accion' => 'Aprobación de Valorización',
                'motivo' => 'Aprobación exitosa de la valorización de compra',
                'cambios' => [
                    [
                        'campo_bd' => 'estado',
                        'campo' => 'Estado',
                        'valor_anterior' => EstadoValorizacionCompra::Propuesta->value,
                        'valor_nuevo' => EstadoValorizacionCompra::Aprobado->value,
                    ],
                ],
            ];

            $valorizacion->update([
                'id_cuenta_bancaria' => $idCuentaBancaria,
                'id_cuenta_detraccion' => $idCuentaDetraccion,
                'tipo_pago' => $tipoPagoStr,
                'estado' => EstadoValorizacionCompra::Aprobado->value,
                'id_empleado_aprobacion' => $idEmpleadoAprobacion,
                'fecha_hora_aprobacion' => now(),
                'log_cambios' => $logCambios,
            ]);

            self::marcarLotesValorizados($valorizacion->detalles);
            self::asignarLeyesLotesDesdeGrupos(
                $valorizacion->detalles,
                $idEmpleadoAprobacion,
                $valorizacion->correlativo ?? (string) $valorizacion->numero_correlativo
            );

            DB::commit();

            $valDetalle = ValorizacionCompraData::get_valorizacion_by_id($id);

            return ApiResponse::success($valDetalle, 'Valorización aprobada exitosamente y saldos de anticipos actualizados.');
        } catch (Exception $e) {
            DB::rollBack();

            return ApiResponse::error($e->getMessage());
        }
    }

    /**
     * Anular o eliminar una valorización (y restituir saldos de anticipos si estaba Aprobada)
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
            $valorizacion = ValorizacionCompraData::find_model($id);
            if (! $valorizacion) {
                DB::rollBack();

                return ApiResponse::error("Valorización con ID {$id} no encontrada.");
            }

            $codigoDisplay = $valorizacion->correlativo ?? $valorizacion->numero_correlativo;

            // Restituir anticipos si alguna transacción de la valorización estaba Aprobada
            foreach ($valorizacion->transaccionesAnticipo as $transaccion) {
                if ($transaccion->estado->value === EstadoTransaccionAnticipo::Aprobado->value) {
                    $anticipo = ValorizacionCompraData::find_anticipo_proveedor((int) $transaccion->id_anticipo_proveedor);
                    if ($anticipo) {
                        $montoRestituir = (float) $transaccion->monto_retirado;
                        $saldoAnterior = (float) $anticipo->saldo_actual;
                        $nuevoSaldo = round($saldoAnterior + $montoRestituir, 3);
                        $estadoAnterior = $anticipo->estado;
                        $nuevoEstado = \App\Shared\Enums\_Generic\EstadoAnticipoProveedor::ConSaldo->value;

                        $cambiosHeader = [
                            [
                                'campo_bd' => 'saldo_actual',
                                'campo' => 'Saldo Actual',
                                'valor_anterior' => '$ '.number_format($saldoAnterior, 2),
                                'valor_nuevo' => '$ '.number_format($nuevoSaldo, 2),
                            ],
                        ];

                        if ($estadoAnterior !== $nuevoEstado) {
                            $cambiosHeader[] = [
                                'campo_bd' => 'estado',
                                'campo' => 'Estado',
                                'valor_anterior' => $estadoAnterior,
                                'valor_nuevo' => $nuevoEstado,
                            ];
                        }

                        $logHeaderAnticipo = $anticipo->log_cambios ?? [];
                        if (! is_array($logHeaderAnticipo)) {
                            $logHeaderAnticipo = json_decode((string) $logHeaderAnticipo, true) ?? [];
                        }
                        $logHeaderAnticipo[] = [
                            'id_empleado' => $idEmpleadoAnulacion,
                            'fecha_hora' => now()->toDateTimeString(),
                            'update_at' => now()->toIso8601String(),
                            'accion' => 'Restitución de Saldo por Anulación/Eliminación',
                            'motivo' => "Restitución de saldo por anulación/eliminación de Valorización {$codigoDisplay} - Monto Restituido: \$ ".number_format($montoRestituir, 2),
                            'cambios' => $cambiosHeader,
                        ];

                        $anticipo->update([
                            'saldo_actual' => $nuevoSaldo,
                            'estado' => $nuevoEstado,
                            'log_cambios' => $logHeaderAnticipo,
                        ]);
                    }
                }

                if ($tipoEliminacion === 'logica') {
                    ValorizacionCompraData::anular_transaccion_anticipo($transaccion, $idEmpleadoAnulacion, (string) $codigoDisplay);
                }
            }

            if ($tipoEliminacion === 'fisica') {
                $detallesParaLiberar = $valorizacion->detalles;

                // Liberar flags de grupo_analisis
                foreach ($detallesParaLiberar as $det) {
                    if ($det->id_grupo_analisis) {
                        $flag = $det->elemento_quimico?->value === 'Oro' ? 'tiene_propuesta_oro' : 'tiene_propuesta_plata';
                        DB::table('grupo_analisis')->where('id', $det->id_grupo_analisis)->update([$flag => 0]);
                    }
                }

                ValorizacionCompraData::delete_detalles_by_valorizacion($id);
                ValorizacionCompraData::delete_transacciones_by_valorizacion($id);
                ValorizacionCompraData::delete_model($valorizacion);

                self::liberarLotesValorizados($id, $detallesParaLiberar);

                DB::commit();

                return ApiResponse::success(null, 'Valorización eliminada físicamente de forma permanente.');
            }

            // Eliminación Lógica
            if ($valorizacion->estado->value === EstadoValorizacionCompra::Anulado->value) {
                DB::rollBack();

                return ApiResponse::error('La valorización ya se encuentra en estado Anulado.');
            }

            $evidenciasAnulacionGuardadas = ! empty($archivosEvidencia)
                ? ArchivoHelper::guardarArchivos('valorizaciones_anuladas', $archivosEvidencia)
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
                        'valor_anterior' => $valorizacion->estado->value,
                        'valor_nuevo' => EstadoValorizacionCompra::Anulado->value,
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
                'estado' => EstadoValorizacionCompra::Anulado->value,
                'id_empleado_anulacion' => $idEmpleadoAnulacion,
                'fecha_hora_anulacion' => now(),
                'motivo_anulacion' => $motivoAnulacion,
                'evidencias_anulacion' => ! empty($evidenciasAnulacionGuardadas) ? json_encode(array_values($evidenciasAnulacionGuardadas)) : null,
                'log_cambios' => $logCambios,
            ]);

            // Liberar flags tiene_propuesta en los grupos de análisis
            foreach ($valorizacion->detalles as $det) {
                if ($det->id_grupo_analisis) {
                    $flag = $det->elemento_quimico?->value === 'Oro' ? 'tiene_propuesta_oro' : 'tiene_propuesta_plata';
                    DB::table('grupo_analisis')->where('id', $det->id_grupo_analisis)->update([$flag => 0]);
                }
            }

            self::liberarLotesValorizados($id, $valorizacion->detalles);

            DB::commit();

            $valDetalle = ValorizacionCompraData::get_valorizacion_by_id($id);

            return ApiResponse::success($valDetalle, 'Valorización anulada lógicamente correctamente.');
        } catch (Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al anular valorización: '.$e->getMessage());
        }
    }

    private static function obtenerEtiquetaCuenta(?int $idCuenta): string
    {
        if (! $idCuenta) {
            return '—';
        }
        $c = DB::table('cuenta_bancaria_proveedor as cb')
            ->leftJoin('banco as b', 'b.id', '=', 'cb.id_banco')
            ->where('cb.id', $idCuenta)
            ->select('cb.numero_cuenta', 'b.nombre as banco_nombre', 'b.abreviatura as banco_abrev')
            ->first();
        if (! $c) {
            return "ID #{$idCuenta}";
        }
        $bancoStr = $c->banco_abrev ?: ($c->banco_nombre ?: '');

        return ! empty($bancoStr) ? "{$bancoStr} - {$c->numero_cuenta}" : $c->numero_cuenta;
    }

    private static function procesarAuditoriaDetallesEdicion(int $idValorizacion, array $detalles): array
    {
        $oldDetalles = ValorizacionCompraDetalle::where('id_valorizacion_compra', $idValorizacion)->get();
        $oldDetMap = [];
        foreach ($oldDetalles as $od) {
            $lg = LoteGuia::with('loteMineral')->find($od->id_lote_guia);
            $corr = $lg && $lg->loteMineral ? ($lg->loteMineral->correlativo ?? $lg->loteMineral->numero_correlativo) : "Lote #{$od->id_lote_guia}";
            $key = "{$od->id_lote_guia}_{$od->elemento_quimico->value}";
            $oldDetMap[$key] = [
                'correlativo' => $corr,
                'elemento' => $od->elemento_quimico->value,
                'inter' => (float) $od->inter,
                'des_inter' => (float) $od->des_inter,
                'recuperacion' => (float) $od->recuperacion,
                'maquila' => (float) $od->maquila,
                'consumo' => (float) $od->consumo,
                'factor' => (float) $od->factor,
                'precio_por_tonelada' => (float) $od->precio_por_tonelada,
                'monto_penalidad' => (float) ($od->monto_penalidad ?? 0),
                'monto_flete' => (float) ($od->monto_flete ?? 0),
                'subtotal' => (float) $od->subtotal,
                'log_cambios' => $od->log_cambios ?? [],
            ];
        }

        $newDetMap = [];
        $newTotalSubtotal = 0;
        foreach ($detalles as $det) {
            $lg = LoteGuia::with('loteMineral')->find($det['id_lote_guia']);
            $corr = $lg && $lg->loteMineral ? ($lg->loteMineral->correlativo ?? $lg->loteMineral->numero_correlativo) : "Lote #{$det['id_lote_guia']}";
            $elem = $det['elemento_quimico'];
            $key = "{$det['id_lote_guia']}_{$elem}";
            $newDetMap[$key] = [
                'correlativo' => $corr,
                'elemento' => $elem,
                'inter' => (float) ($det['inter'] ?? 0),
                'des_inter' => (float) ($det['des_inter'] ?? 0),
                'recuperacion' => (float) ($det['recuperacion'] ?? 0),
                'maquila' => (float) ($det['maquila'] ?? 0),
                'consumo' => (float) ($det['consumo'] ?? 0),
                'factor' => (float) ($det['factor'] ?? 1.0),
                'monto_penalidad' => (float) ($det['monto_penalidad'] ?? 0),
                'monto_flete' => (float) ($det['monto_flete'] ?? 0),
            ];

            if ($lg && $lg->loteMineral) {
                $lote = $lg->loteMineral;
                if (self::loteEsSinValorComercial($lote)) {
                    $subtotalFinalTmp = 0.0;
                } else {
                    $pesoNeto = (float) ($lote->peso_neto ?? 0);
                    $pesoSeco = $pesoNeto * (1 - ((float) $lote->ley_humedad / 100));
                    $elementoEnumTmp = ElementoQuimicoValorizacion::tryFrom($elem) ?? ElementoQuimicoValorizacion::Oro;
                    $leyTmp = $elementoEnumTmp === ElementoQuimicoValorizacion::Oro ? (float) $lote->ley_oro : (float) $lote->ley_plata;
                    $ptnTmp = (((float) ($det['inter'] ?? 0) - (float) ($det['des_inter'] ?? 0)) * $leyTmp * ((float) ($det['recuperacion'] ?? 0) / 100) - (float) ($det['maquila'] ?? 0) - (float) ($det['consumo'] ?? 0)) * (float) ($det['factor'] ?? 1.0);
                    $subtotalSinDescTmp = round(($ptnTmp * $pesoSeco) / 1000, 2);
                    $descTmp = round((float) ($det['monto_penalidad'] ?? 0) + (float) ($det['monto_flete'] ?? 0), 2);
                    $subtotalFinalTmp = round($subtotalSinDescTmp - $descTmp, 2);
                }
                $newTotalSubtotal += $subtotalFinalTmp;
            }
        }

        $cambios = [];
        $oldTotalSubtotal = round((float) $oldDetalles->sum('subtotal'), 2);
        if (abs($oldTotalSubtotal - $newTotalSubtotal) > 0.01) {
            $cambios[] = [
                'campo_bd' => 'total_subtotal',
                'campo' => 'Total Valorización',
                'valor_anterior' => '$ '.number_format($oldTotalSubtotal, 2),
                'valor_nuevo' => '$ '.number_format($newTotalSubtotal, 2),
            ];
        }

        foreach ($oldDetMap as $key => $oldInfo) {
            if (! isset($newDetMap[$key])) {
                $cambios[] = [
                    'campo_bd' => 'lote_removido',
                    'campo' => 'Lote en valorización removido',
                    'valor_anterior' => "{$oldInfo['correlativo']} ({$oldInfo['elemento']})",
                    'valor_nuevo' => '—',
                ];
            }
        }

        foreach (array_diff_key($newDetMap, $oldDetMap) as $key => $newInfo) {
            $cambios[] = [
                'campo_bd' => 'lote_agregado',
                'campo' => 'Lote en valorización agregado',
                'valor_anterior' => '—',
                'valor_nuevo' => "{$newInfo['correlativo']} ({$newInfo['elemento']})",
            ];
        }

        return [
            'cambios' => $cambios,
            'new_total_subtotal' => $newTotalSubtotal,
            'old_det_map' => $oldDetMap,
        ];
    }

    /**
     * Marca los lotes de una valorización como valorizados para su elemento químico.
     * Solo prende el flag, sin verificar otras valorizaciones (apto para aprobar).
     *
     * @param  iterable<ValorizacionCompraDetalle>  $detalles
     */
    private static function marcarLotesValorizados(iterable $detalles): void
    {
        $porElemento = [];
        foreach ($detalles as $det) {
            $loteGuia = LoteGuia::with('loteMineral', 'particionLoteMineral.loteMineral')->find($det->id_lote_guia);
            $lote = $loteGuia?->loteMineral ?? $loteGuia?->particionLoteMineral?->loteMineral;
            if (! $lote) {
                continue;
            }
            $elemento = $det->elemento_quimico?->value;
            if (! in_array($elemento, ['Oro', 'Plata'], true)) {
                continue;
            }
            $porElemento[$elemento][] = (int) $lote->id;
        }

        foreach ($porElemento as $elemento => $idsLotes) {
            $idsLotes = array_values(array_unique($idsLotes));
            $columna = $elemento === 'Oro' ? 'esta_valorizado_oro' : 'esta_valorizado_plata';
            LoteMineral::whereIn('id', $idsLotes)->update([$columna => 1]);
        }
    }

    /**
     * Al aprobar una propuesta de valorización, actualiza en lote_mineral
     * las leyes (oro, plata, humedad, recuperación) provenientes del grupo de análisis elegido.
     *
     * @param  iterable<ValorizacionCompraDetalle>  $detalles
     */
    private static function asignarLeyesLotesDesdeGrupos(iterable $detalles, int $idEmpleado, string $correlativoVal): void
    {
        $lotesDetalles = [];
        foreach ($detalles as $det) {
            if (empty($det->id_grupo_analisis)) {
                continue;
            }
            $loteGuia = LoteGuia::with('loteMineral', 'particionLoteMineral.loteMineral')->find($det->id_lote_guia);
            $lote = $loteGuia?->loteMineral ?? $loteGuia?->particionLoteMineral?->loteMineral;
            if (! $lote) {
                continue;
            }
            $lotesDetalles[$lote->id][] = [
                'lote' => $lote,
                'detalle' => $det,
            ];
        }

        foreach ($lotesDetalles as $idLote => $items) {
            $lote = $items[0]['lote'];
            $updateData = [];
            $cambiosLote = [];
            $nombresGrupos = [];

            foreach ($items as $item) {
                $det = $item['detalle'];
                $grupo = DB::table('grupo_analisis')->where('id', $det->id_grupo_analisis)->first();
                if (! $grupo) {
                    continue;
                }
                $nombresGrupos[] = $grupo->nombre ?? "Grupo {$grupo->numero_correlativo}";

                $elemento = $det->elemento_quimico?->value;

                // 1. Ley según el elemento químico valorizado
                if ($elemento === 'Oro') {
                    $leyOroVal = round((float) $grupo->ley_oro_cierre, 3);
                    if (abs((float) ($lote->ley_oro ?? 0) - $leyOroVal) > 0.0001) {
                        $cambiosLote[] = [
                            'campo_bd' => 'ley_oro',
                            'campo' => 'Ley Oro',
                            'valor_anterior' => (string) ($lote->ley_oro ?? 0),
                            'valor_nuevo' => (string) $leyOroVal,
                        ];
                    }
                    $updateData['ley_oro'] = $leyOroVal;
                } elseif ($elemento === 'Plata') {
                    $leyPlataVal = round((float) $grupo->ley_plata_cierre, 3);
                    if (abs((float) ($lote->ley_plata ?? 0) - $leyPlataVal) > 0.0001) {
                        $cambiosLote[] = [
                            'campo_bd' => 'ley_plata',
                            'campo' => 'Ley Plata',
                            'valor_anterior' => (string) ($lote->ley_plata ?? 0),
                            'valor_nuevo' => (string) $leyPlataVal,
                        ];
                    }
                    $updateData['ley_plata'] = $leyPlataVal;
                }

                // 2. Ley de humedad de cierre si está disponible (> 0)
                if ((float) $grupo->ley_humedad_cierre > 0 && ! isset($updateData['ley_humedad'])) {
                    $leyHumVal = round((float) $grupo->ley_humedad_cierre, 3);
                    if (abs((float) ($lote->ley_humedad ?? 0) - $leyHumVal) > 0.0001) {
                        $cambiosLote[] = [
                            'campo_bd' => 'ley_humedad',
                            'campo' => 'Ley Humedad',
                            'valor_anterior' => (string) ($lote->ley_humedad ?? 0),
                            'valor_nuevo' => (string) $leyHumVal,
                        ];
                    }
                    $updateData['ley_humedad'] = $leyHumVal;
                }

                // 3. Ley de recuperación de cierre si está disponible (> 0)
                if ((float) $grupo->ley_recuperacion_cierre > 0 && ! isset($updateData['ley_recuperacion'])) {
                    $leyRecVal = round((float) $grupo->ley_recuperacion_cierre, 3);
                    if (abs((float) ($lote->ley_recuperacion ?? 0) - $leyRecVal) > 0.0001) {
                        $cambiosLote[] = [
                            'campo_bd' => 'ley_recuperacion',
                            'campo' => 'Ley Recuperación',
                            'valor_anterior' => (string) ($lote->ley_recuperacion ?? 0),
                            'valor_nuevo' => (string) $leyRecVal,
                        ];
                    }
                    $updateData['ley_recuperacion'] = $leyRecVal;
                }

                // 4. Si el lote aún no tiene valorizado el otro elemento y el grupo tiene ley de cierre > 0, tomarla también
                if (! isset($updateData['ley_oro']) && ! $lote->esta_valorizado_oro && (float) $grupo->ley_oro_cierre > 0) {
                    $leyOroVal = round((float) $grupo->ley_oro_cierre, 3);
                    if (abs((float) ($lote->ley_oro ?? 0) - $leyOroVal) > 0.0001) {
                        $cambiosLote[] = [
                            'campo_bd' => 'ley_oro',
                            'campo' => 'Ley Oro',
                            'valor_anterior' => (string) ($lote->ley_oro ?? 0),
                            'valor_nuevo' => (string) $leyOroVal,
                        ];
                    }
                    $updateData['ley_oro'] = $leyOroVal;
                }
                if (! isset($updateData['ley_plata']) && ! $lote->esta_valorizado_plata && (float) $grupo->ley_plata_cierre > 0) {
                    $leyPlataVal = round((float) $grupo->ley_plata_cierre, 3);
                    if (abs((float) ($lote->ley_plata ?? 0) - $leyPlataVal) > 0.0001) {
                        $cambiosLote[] = [
                            'campo_bd' => 'ley_plata',
                            'campo' => 'Ley Plata',
                            'valor_anterior' => (string) ($lote->ley_plata ?? 0),
                            'valor_nuevo' => (string) $leyPlataVal,
                        ];
                    }
                    $updateData['ley_plata'] = $leyPlataVal;
                }
            }

            if (! empty($updateData)) {
                if (! empty($cambiosLote)) {
                    $logLote = $lote->log_cambios ?? [];
                    if (! is_array($logLote)) {
                        $logLote = json_decode((string) $logLote, true) ?? [];
                    }
                    $strGrupos = implode(', ', array_unique($nombresGrupos));
                    $logLote[] = [
                        'id_empleado' => $idEmpleado,
                        'fecha_hora' => now()->toDateTimeString(),
                        'update_at' => now()->toIso8601String(),
                        'accion' => 'Actualización de Leyes por Valorización Aprobada',
                        'motivo' => "Leyes actualizadas desde {$strGrupos} al aprobar valorización {$correlativoVal}",
                        'cambios' => $cambiosLote,
                    ];
                    $updateData['log_cambios'] = $logLote;
                }

                $lote->update($updateData);
            }
        }
    }

    /**
     * Resetea el flag esta_valorizado_X de los lotes de una valorización, siempre que
     * no exista otra valorización viva (no anulada) que valorice el mismo lote+elemento.
     *
     * @param  iterable<ValorizacionCompraDetalle>  $detalles
     */
    private static function liberarLotesValorizados(int $idValorizacionActual, iterable $detalles): void
    {
        $estadoAnulado = EstadoValorizacionCompra::Anulado->value;

        foreach ($detalles as $det) {
            $loteGuia = LoteGuia::with('loteMineral', 'particionLoteMineral.loteMineral')->find($det->id_lote_guia);
            $lote = $loteGuia?->loteMineral ?? $loteGuia?->particionLoteMineral?->loteMineral;
            if (! $lote) {
                continue;
            }
            $elemento = $det->elemento_quimico?->value;
            if (! in_array($elemento, ['Oro', 'Plata'], true)) {
                continue;
            }
            $columna = $elemento === 'Oro' ? 'esta_valorizado_oro' : 'esta_valorizado_plata';

            $existeOtra = DB::table('valorizacion_compramineral_detalle as vcd')
                ->join('valorizacion_compra as vc', 'vc.id', '=', 'vcd.id_valorizacion_compra')
                ->join('lote_guia as lg_check', 'lg_check.id', '=', 'vcd.id_lote_guia')
                ->leftJoin('particion_lote_mineral as plm', 'plm.id', '=', 'lg_check.id_particion_lote_mineral')
                ->whereRaw('COALESCE(lg_check.id_lote_mineral, plm.id_lote_mineral) = ?', [$lote->id])
                ->where('vc.estado', '!=', $estadoAnulado)
                ->where('vc.id', '!=', $idValorizacionActual)
                ->where('vcd.elemento_quimico', $elemento)
                ->exists();

            if (! $existeOtra) {
                LoteMineral::where('id', $lote->id)->update([$columna => 0]);
            }
        }
    }

    /**
     * Determinar si un lote de mineral fue cerrado sin valor comercial
     */
    private static function loteEsSinValorComercial(?LoteMineral $lote): bool
    {
        if (! $lote) {
            return false;
        }

        return $lote->con_valor_comercial === 0 || $lote->con_valor_comercial === false || $lote->con_valor_comercial === '0';
    }

    /**
     * Solicitar Orden de Compra para una valorización
     */
    public static function solicitar_oc(int $id, int $idEmpleado): array
    {
        $valorizacion = ValorizacionCompraData::find_model($id);
        if (! $valorizacion) {
            return ApiResponse::error("Valorización con ID {$id} no encontrada.");
        }

        $empleado = DB::table('empleado')->where('id', $idEmpleado)->first();
        $nombreEmpleado = $empleado ? trim($empleado->nombre.' '.$empleado->apellido) : "Empleado #{$idEmpleado}";

        $logCambios = $valorizacion->log_cambios ?? [];
        $logCambios[] = [
            'fecha_hora' => now()->toDateTimeString(),
            'update_at' => now()->toIso8601String(),
            'id_empleado' => $idEmpleado,
            'accion' => 'Solicitud de Orden de Compra',
            'motivo' => "Solicitud de OC generada por {$nombreEmpleado}",
            'cambios' => [
                [
                    'campo_bd' => 'fecha_hora_solicitud_oc',
                    'campo' => 'Fecha de Solicitud OC',
                    'valor_anterior' => $valorizacion->fecha_hora_solicitud_oc ? $valorizacion->fecha_hora_solicitud_oc->toDateTimeString() : '—',
                    'valor_nuevo' => now()->toDateTimeString(),
                ],
                [
                    'campo_bd' => 'id_empleado_solicita_oc',
                    'campo' => 'Solicitado Por',
                    'valor_anterior' => $valorizacion->empleadoSolicitaOc ? trim($valorizacion->empleadoSolicitaOc->nombre.' '.$valorizacion->empleadoSolicitaOc->apellido) : '—',
                    'valor_nuevo' => $nombreEmpleado,
                ],
            ],
        ];

        $valorizacion->update([
            'id_empleado_solicita_oc' => $idEmpleado,
            'fecha_hora_solicitud_oc' => now(),
            'log_cambios' => $logCambios,
        ]);

        $valDetalle = ValorizacionCompraData::get_valorizacion_by_id($id);

        return ApiResponse::success($valDetalle, 'Orden de Compra solicitada correctamente.');
    }
}
