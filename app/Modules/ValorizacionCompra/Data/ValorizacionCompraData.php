<?php

namespace App\Modules\ValorizacionCompra\Data;

use App\Models\ComprobanteCompra;
use App\Models\LoteMineral;
use App\Models\ValorizacionCompra;
use App\Models\ValorizacionCompraDetalle;
use App\Shared\Enums\ContabilidadCompra\EstadoComprobanteCompra;
use App\Shared\Enums\ValorizacionCompra\EstadoValorizacionCompra;
use App\Shared\Responses\_Generic\RES_CambiosLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ValorizacionCompraData
{
    /**
     * Base query para relaciones de ValorizacionCompra
     *
     * @return Builder<ValorizacionCompra>
     */
    private static function queryBase(): Builder
    {
        return ValorizacionCompra::query()->with([
            'proveedor:id,razon_social,ruc,direccion',
            'concesion:id,nombre,codigo_reinfo,id_departamento,id_provincia,id_distrito',
            'cuentaBancaria:id,numero_cuenta,moneda,id_banco,cci',
            'cuentaBancaria.banco:id,nombre',
            'cuentaDetraccion:id,numero_cuenta,moneda,id_banco,cci',
            'cuentaDetraccion.banco:id,nombre',
            'empleadoRegistro:id,nombre,apellido',
            'empleadoAprobacion:id,nombre,apellido',
            'empleadoAnulacion:id,nombre,apellido',
            'empleadoSolicitaOc:id,nombre,apellido',
            'detalles',
            'detalles.grupoAnalisis:id,nombre,numero_correlativo,ley_oro_cierre,ley_plata_cierre,ley_humedad_cierre',
            'detalles.loteGuia',
            'detalles.loteGuia.loteMineral:id,numero_correlativo,correlativo,ley_humedad,ley_oro,ley_plata,peso_neto',
            'detalles.loteGuia.particionLoteMineral.loteMineral:id,numero_correlativo,correlativo,ley_humedad,ley_oro,ley_plata,peso_neto',
            'detalles.loteGuia.guiaPrimerTramo:id,guia_remitente,guia_transportista,sin_guia_transportista,fecha_en_planta',
            'transaccionesAnticipo',
            'transaccionesAnticipo.anticipo:id,serie_factura,numero_factura,saldo_inicial,saldo_actual',
            'comprobanteCompra:id,id_valorizacion_compra,serie,numero,fecha_emision,estado,monto_neto,monto_neto_final,monto_detraccion_soles,monto_detraccion_soles_final,avance_pago_neto,avance_pago_detraccion',
            'comprobanteCompra.notas',
        ]);
    }

    /**
     * Formatear un modelo ValorizacionCompra a arreglo de respuesta
     */
    public static function format_valorizacion(ValorizacionCompra $item): array
    {
        $totalSubtotal = (float) $item->detalles->sum('subtotal');
        $totalAnticipos = (float) $item->transaccionesAnticipo->sum('monto_retirado');
        $montoTransferencia = max(0, $totalSubtotal - $totalAnticipos);

        $anio = $item->created_at ? $item->created_at->format('y') : date('y');
        $correlativoCalculado = $item->correlativo ?? "{$anio}-VAL-".str_pad((string) $item->numero_correlativo, 5, '0', STR_PAD_LEFT);

        // Ubicación de concesión
        $ubicacionConcesion = '—';
        if ($item->concesion) {
            $distrito = $item->concesion->id_distrito ? \Illuminate\Support\Facades\DB::table('distrito')->where('id', $item->concesion->id_distrito)->value('nombre') : null;
            $provincia = $item->concesion->id_provincia ? \Illuminate\Support\Facades\DB::table('provincia')->where('id', $item->concesion->id_provincia)->value('nombre') : null;
            $departamento = $item->concesion->id_departamento ? \Illuminate\Support\Facades\DB::table('departamento')->where('id', $item->concesion->id_departamento)->value('nombre') : null;
            $partesUbi = array_filter([$distrito, $provincia, $departamento]);
            if (! empty($partesUbi)) {
                $ubicacionConcesion = implode(' - ', $partesUbi);
            }
        }

        // Guías de remisión del primer tramo
        $guiasRemitente = [];
        $guiasTransportista = [];
        $lotesVistos = [];
        $tmhTotalBalanza = 0.0;
        $lotesGuiaVistos = [];
        $tmhTotalGr = 0.0;

        foreach ($item->detalles as $d) {
            $lg = $d->loteGuia;
            if ($lg && ! isset($lotesGuiaVistos[$lg->id])) {
                $lotesGuiaVistos[$lg->id] = true;
                $lm = $lg->loteMineral ?? $lg->particionLoteMineral?->loteMineral;
                $pesoGr = ($lg->peso_neto !== null && (float) $lg->peso_neto > 0)
                    ? (float) $lg->peso_neto
                    : (float) ($lm?->peso_neto ?? 0);
                $tmhTotalGr += $pesoGr;
            }

            $lm = $lg ? ($lg->loteMineral ?? $lg->particionLoteMineral?->loteMineral) : null;
            if ($lm && ! isset($lotesVistos[$lm->id])) {
                $lotesVistos[$lm->id] = true;
                $tmhTotalBalanza += (float) ($lm->peso_neto ?? 0);
            }

            $gpt = $lg ? $lg->guiaPrimerTramo : null;
            if ($gpt) {
                if (! empty($gpt->guia_remitente)) {
                    $guiasRemitente[] = trim((string) $gpt->guia_remitente);
                }
                if (! $gpt->sin_guia_transportista && ! empty($gpt->guia_transportista)) {
                    $guiasTransportista[] = trim((string) $gpt->guia_transportista);
                }
            }
        }

        if ($tmhTotalGr <= 0 && $tmhTotalBalanza > 0) {
            $tmhTotalGr = $tmhTotalBalanza;
        }

        $guiaRemitenteStr = ! empty($guiasRemitente) ? implode(', ', array_unique($guiasRemitente)) : '—';
        $guiaTransportistaStr = ! empty($guiasTransportista) ? implode(', ', array_unique($guiasTransportista)) : '—';

        // Cálculos económicos para el reporte de liquidación:
        // El subtotal base para impuestos y detracción es tras descontar flete/penalidad y anticipos
        $pctIgv = $item->porcentaje_igv !== null ? (float) $item->porcentaje_igv : 18.00;
        $pctDetraccion = $item->porcentaje_detraccion !== null ? (float) $item->porcentaje_detraccion : 10.00;

        $subtotalNeto = max(0.0, round($totalSubtotal - $totalAnticipos, 2));
        $igv = round($subtotalNeto * ($pctIgv / 100), 2);
        $totalConIgv = round($subtotalNeto + $igv, 2);
        $detraccion = round($totalConIgv * ($pctDetraccion / 100), 2);
        $netoAPagar = max(0.0, round($totalConIgv - $detraccion, 2));

        // Comprobante / Factura asociada
        $comprobante = $item->relationLoaded('comprobanteCompra')
            ? $item->comprobanteCompra
            : ComprobanteCompra::where('id_valorizacion_compra', $item->id)
                ->where('estado', '!=', EstadoComprobanteCompra::Anulado->value)
                ->first();

        $facturaCodigo = null;
        $facturaFecha = null;
        if ($comprobante && ($comprobante->estado instanceof EstadoComprobanteCompra ? $comprobante->estado !== EstadoComprobanteCompra::Anulado : $comprobante->estado !== EstadoComprobanteCompra::Anulado->value)) {
            $serie = trim((string) $comprobante->serie);
            $numero = trim((string) $comprobante->numero);
            $facturaCodigo = ($serie !== '' && $numero !== '') ? "{$serie}-{$numero}" : ($serie ?: $numero);
            $facturaFecha = $comprobante->fecha_emision instanceof \DateTimeInterface
                ? $comprobante->fecha_emision->format('Y-m-d')
                : (string) $comprobante->fecha_emision;
        }

        // Notas de crédito / débito emitidas contra el comprobante de esta valorización.
        // Alimentan el "ticket de valorización por dirimencia".
        $notasComprobante = [];
        if ($comprobante) {
            $notas = $comprobante->relationLoaded('notas')
                ? $comprobante->notas
                : \App\Models\ComprobanteNota::where('id_comprobante_compra', $comprobante->id)->get();

            foreach ($notas as $nota) {
                $tipoNota = $nota->tipo instanceof \App\Shared\Enums\ContabilidadCompra\TipoNotaComprobante
                    ? $nota->tipo->value
                    : (string) $nota->tipo;

                $notasComprobante[] = [
                    'id' => (int) $nota->id,
                    'tipo' => $tipoNota,
                    'tipo_descripcion' => $nota->tipo instanceof \App\Shared\Enums\ContabilidadCompra\TipoNotaComprobante
                        ? $nota->tipo->descripcion()
                        : (string) $tipoNota,
                    'codigo_nota' => $nota->codigo_nota,
                    'motivo' => $nota->motivo,
                    'motivo_descripcion' => \App\Modules\ContabilidadCompra\Data\ComprobanteNotaData::resolver_descripcion_motivo(
                        (string) $tipoNota,
                        (string) ($nota->motivo ?? '')
                    ),
                    'monto' => (float) $nota->monto,
                    'fecha_emision' => $nota->fecha_emision instanceof \DateTimeInterface
                        ? $nota->fecha_emision->format('Y-m-d')
                        : (string) $nota->fecha_emision,
                    'es_anulado' => (bool) $nota->es_anulado,
                ];
            }
        }

        $notasVigentes = array_values(array_filter(
            $notasComprobante,
            fn (array $n): bool => ! $n['es_anulado']
        ));

        $montoNetoComprobante = $comprobante ? (float) $comprobante->monto_neto : 0.0;
        $montoNetoFinalComprobante = $comprobante ? (float) $comprobante->monto_neto_final : 0.0;
        // Fuente de verdad del monto pagable: el snapshot ajustado del comprobante.
        $montoNetoEfectivo = $montoNetoFinalComprobante;

        $montoDetraccionSolesComprobante = $comprobante ? (float) $comprobante->monto_detraccion_soles : 0.0;
        $montoDetraccionSolesFinalComprobante = $comprobante ? (float) $comprobante->monto_detraccion_soles_final : 0.0;
        $montoDetraccionSolesEfectivo = $montoDetraccionSolesFinalComprobante;

        // Saldos a favor: lo ya pagado que supera el monto a pagar tras las notas de crédito.
        $saldoAFavorNeto = $comprobante
            ? max(0.0, round((float) $comprobante->avance_pago_neto - $montoNetoEfectivo, 2))
            : 0.0;
        $saldoAFavorDetraccion = $comprobante
            ? max(0.0, round((float) $comprobante->avance_pago_detraccion - $montoDetraccionSolesEfectivo, 2))
            : 0.0;

        return [
            'id' => $item->id,
            'numero_correlativo' => $item->numero_correlativo,
            'correlativo' => $correlativoCalculado,
            'numero_version' => $item->numero_version !== null ? (int) $item->numero_version : 1,
            'uuid_grupo' => ! empty($item->uuid_grupo) ? (string) $item->uuid_grupo : "legacy-{$item->id}",
            'id_proveedor_minero' => $item->id_proveedor_minero,
            'proveedor_nombre' => $item->proveedor ? $item->proveedor->razon_social : null,
            'proveedor_ruc' => $item->proveedor ? $item->proveedor->ruc : null,
            'proveedor_direccion' => $item->proveedor ? $item->proveedor->direccion : null,
            'id_concesion' => $item->id_concesion,
            'concesion_nombre' => $item->concesion ? $item->concesion->nombre : null,
            'concesion_codigo_reinfo' => $item->concesion ? $item->concesion->codigo_reinfo : null,
            'concesion_ubicacion' => $ubicacionConcesion,
            'id_cuenta_bancaria' => $item->id_cuenta_bancaria,
            'cuenta_bancaria_info' => $item->cuentaBancaria ? ($item->cuentaBancaria->banco ? $item->cuentaBancaria->banco->nombre : '').' - '.$item->cuentaBancaria->numero_cuenta : null,
            'cuenta_bancaria_banco' => $item->cuentaBancaria?->banco?->nombre,
            'cuenta_bancaria_numero' => $item->cuentaBancaria?->numero_cuenta,
            'cuenta_bancaria_cci' => $item->cuentaBancaria?->cci,
            'id_cuenta_detraccion' => $item->id_cuenta_detraccion,
            'cuenta_detraccion_info' => $item->cuentaDetraccion ? ($item->cuentaDetraccion->banco ? $item->cuentaDetraccion->banco->nombre : '').' - '.$item->cuentaDetraccion->numero_cuenta : null,
            'cuenta_detraccion_banco' => $item->cuentaDetraccion?->banco?->nombre,
            'cuenta_detraccion_numero' => $item->cuentaDetraccion?->numero_cuenta,
            'cuenta_detraccion_cci' => $item->cuentaDetraccion?->cci,
            'tipo_pago' => $item->tipo_pago ? $item->tipo_pago->value : null,
            'estado' => $item->estado ? $item->estado->value : null,
            'porcentaje_igv' => $pctIgv,
            'porcentaje_detraccion' => $pctDetraccion,
            'guia_remitente' => $guiaRemitenteStr,
            'guia_transportista' => $guiaTransportistaStr,
            'lugar_compra' => 'KM. 573 OTR. PANAMERICANA NORTE (B083464-13-01) LA LIBERTAD - TRUJILLO - HUANCHACO',
            'tmh_total_gr' => round($tmhTotalGr, 3),
            'tmh_total_balanza' => round($tmhTotalBalanza, 3),
            'factura_asociada' => $facturaCodigo,
            'fecha_factura' => $facturaFecha,
            'tiene_factura' => ! empty($facturaCodigo),
            'con_dirimencia' => (bool) $item->con_dirimencia,
            'correlativo_dirimencia' => $item->correlativo_dirimencia,
            'numero_correlativo_dirimencia' => $item->numero_correlativo_dirimencia !== null
                ? (int) $item->numero_correlativo_dirimencia
                : null,
            'fecha_hora_dirimencia' => $item->fecha_hora_dirimencia
                ? ($item->fecha_hora_dirimencia instanceof \DateTimeInterface
                    ? $item->fecha_hora_dirimencia->format('Y-m-d H:i:s')
                    : (string) $item->fecha_hora_dirimencia)
                : null,
            'comprobante_id' => $comprobante ? (int) $comprobante->id : null,
            'notas_comprobante' => $notasComprobante,
            'tiene_notas' => count($notasVigentes) > 0,
            'monto_neto_comprobante' => $montoNetoComprobante,
            'monto_neto_comprobante_efectivo' => $montoNetoEfectivo,
            'monto_detraccion_soles_comprobante' => $montoDetraccionSolesComprobante,
            'monto_detraccion_soles_comprobante_efectivo' => $montoDetraccionSolesEfectivo,
            'saldo_a_favor_neto' => $saldoAFavorNeto,
            'saldo_a_favor_detraccion' => $saldoAFavorDetraccion,
            'igv' => $igv,
            'total_con_igv' => $totalConIgv,
            'monto_detraccion' => $detraccion,
            'neto_a_pagar' => $netoAPagar,
            'created_at' => $item->created_at ? $item->created_at->format('Y-m-d H:i:s') : null,
            'fecha_hora_valorizacion' => $item->fecha_hora_valorizacion ? ($item->fecha_hora_valorizacion instanceof \DateTimeInterface ? $item->fecha_hora_valorizacion->format('Y-m-d H:i:s') : (string) $item->fecha_hora_valorizacion) : null,
            'fecha_hora_aprobacion' => $item->fecha_hora_aprobacion ? $item->fecha_hora_aprobacion->format('Y-m-d H:i:s') : null,
            'fecha_hora_anulacion' => $item->fecha_hora_anulacion ? ($item->fecha_hora_anulacion instanceof \DateTimeInterface ? $item->fecha_hora_anulacion->format('Y-m-d H:i:s') : date('Y-m-d H:i:s', (int) $item->fecha_hora_anulacion)) : null,
            'id_empleado_solicita_oc' => $item->id_empleado_solicita_oc,
            'fecha_hora_solicitud_oc' => $item->fecha_hora_solicitud_oc ? ($item->fecha_hora_solicitud_oc instanceof \DateTimeInterface ? $item->fecha_hora_solicitud_oc->format('Y-m-d H:i:s') : (string) $item->fecha_hora_solicitud_oc) : null,
            'empleado_solicita_oc' => $item->empleadoSolicitaOc ? trim($item->empleadoSolicitaOc->nombre.' '.$item->empleadoSolicitaOc->apellido) : null,
            'id_empleado_anulacion' => $item->id_empleado_anulacion,
            'empleado_registro' => $item->empleadoRegistro ? trim($item->empleadoRegistro->nombre.' '.$item->empleadoRegistro->apellido) : null,
            'empleado_aprobacion' => $item->empleadoAprobacion ? trim($item->empleadoAprobacion->nombre.' '.$item->empleadoAprobacion->apellido) : null,
            'empleado_anulacion' => $item->empleadoAnulacion ? trim($item->empleadoAnulacion->nombre.' '.$item->empleadoAnulacion->apellido) : null,
            'motivo_anulacion' => $item->motivo_anulacion,
            'evidencias_anulacion' => isset($item->evidencias_anulacion) ? (is_array($item->evidencias_anulacion) ? $item->evidencias_anulacion : (json_decode((string) $item->evidencias_anulacion, true) ?? [])) : [],
            'total_subtotal' => round($totalSubtotal, 2),
            'total_anticipos' => round($totalAnticipos, 2),
            'monto_transferencia' => round($montoTransferencia, 2),
            'evidencias' => isset($item->evidencias) ? (is_array($item->evidencias) ? $item->evidencias : (json_decode((string) $item->evidencias, true) ?? [])) : [],
            'log_cambios' => $item->log_cambios ?? [],
            'detalles' => (function () use ($item) {
                // Pre-calcular peso contabilizado por lote_mineral según regla de negocio:
                // Si el lote tiene particiones: suma de lote_guia.peso_neto de sus particiones
                // Si no tiene: lote_guia.peso_neto del lote
                $loteIds = [];
                foreach ($item->detalles as $d) {
                    $lg = $d->loteGuia;
                    $lm = $lg ? ($lg->loteMineral ?? $lg->particionLoteMineral?->loteMineral) : null;
                    if ($lm) {
                        $loteIds[$lm->id] = true;
                    }
                }

                $pesosContabilizadosMap = [];
                foreach (array_keys($loteIds) as $idLote) {
                    $tieneParticiones = \Illuminate\Support\Facades\DB::table('particion_lote_mineral')
                        ->where('id_lote_mineral', $idLote)
                        ->exists();

                    if ($tieneParticiones) {
                        $suma = (float) \Illuminate\Support\Facades\DB::table('lote_guia')
                            ->whereIn('id_particion_lote_mineral', function ($q) use ($idLote) {
                                $q->select('id')
                                    ->from('particion_lote_mineral')
                                    ->where('id_lote_mineral', $idLote);
                            })
                            ->sum('peso_neto');
                        $pesosContabilizadosMap[$idLote] = $suma;
                    } else {
                        $peso = (float) \Illuminate\Support\Facades\DB::table('lote_guia')
                            ->where('id_lote_mineral', $idLote)
                            ->value('peso_neto');
                        $pesosContabilizadosMap[$idLote] = $peso;
                    }
                }

                return $item->detalles->map(function ($d) use ($pesosContabilizadosMap) {
                    $lg = $d->loteGuia;
                    $lm = $lg ? ($lg->loteMineral ?? $lg->particionLoteMineral?->loteMineral) : null;
                    $gpt = $lg ? $lg->guiaPrimerTramo : null;

                    $tmh = $lm ? (float) ($lm->peso_neto ?? 0) : 0;
                    $leyHumedad = $lm ? (float) $lm->ley_humedad : 0;
                    $tms = round($tmh * (1 - ($leyHumedad / 100)), 4);

                    $idLote = $lm ? (int) $lm->id : null;
                    $pesoContab = $idLote && isset($pesosContabilizadosMap[$idLote]) && $pesosContabilizadosMap[$idLote] > 0
                        ? $pesosContabilizadosMap[$idLote]
                        : ($lg && $lg->peso_neto ? (float) $lg->peso_neto : $tmh);

                    $idGrupo = $d->id_grupo_analisis ? (int) $d->id_grupo_analisis : null;
                    $ga = $d->grupoAnalisis;
                    $leyDetalle = $ga
                        ? (float) ($d->elemento_quimico && $d->elemento_quimico->value === 'Oro' ? $ga->ley_oro_cierre : $ga->ley_plata_cierre)
                        : ($lm ? (float) ($d->elemento_quimico && $d->elemento_quimico->value === 'Oro' ? $lm->ley_oro : $lm->ley_plata) : 0);
                    $leyHumedadDetalle = $ga && $ga->ley_humedad_cierre > 0 ? (float) $ga->ley_humedad_cierre : $leyHumedad;

                    return [
                        'id' => $d->id,
                        'id_valorizacion_compra' => $d->id_valorizacion_compra,
                        'id_lote_guia' => $d->id_lote_guia,
                        'id_grupo_analisis' => $idGrupo,
                        'grupo_analisis_nombre' => $ga ? $ga->nombre : null,
                        'id_condicion_comercial' => $d->id_condicion_comercial,
                        'id_valor_elemento_quimico' => $d->id_valor_elemento_quimico,
                        'elemento_quimico' => $d->elemento_quimico ? $d->elemento_quimico->value : null,
                        'numero_correlativo' => $lm ? $lm->numero_correlativo : null,
                        'lote_correlativo' => $lm ? $lm->correlativo : null,
                        'grr' => $gpt ? $gpt->guia_remitente : null,
                        'grt' => $gpt && ! $gpt->sin_guia_transportista ? $gpt->guia_transportista : null,
                        'fecha_ingreso' => $gpt?->fecha_en_planta ? $gpt->fecha_en_planta->format('Y-m-d') : ($lm?->created_at ? $lm->created_at->format('Y-m-d') : null),
                        'tmh' => $tmh,
                        'peso_neto_lote' => $tmh,
                        'peso_neto_guia' => $lg ? (float) ($lg->peso_neto ?? 0) : 0,
                        'peso_contabilizado' => $pesoContab,
                        'id_lote_mineral' => $idLote,
                        'id_particion_lote_mineral' => $lg && $lg->id_particion_lote_mineral ? (int) $lg->id_particion_lote_mineral : null,
                        'ley_humedad' => $leyHumedadDetalle,
                        'tms' => $tms,
                        'ley' => $leyDetalle,
                        'inter' => (float) $d->inter,
                        'des_inter' => (float) $d->des_inter,
                        'recuperacion' => (float) $d->recuperacion,
                        'maquila' => (float) $d->maquila,
                        'consumo' => (float) $d->consumo,
                        'factor' => (float) $d->factor,
                        'precio_por_tonelada' => (float) $d->precio_por_tonelada,
                        'monto_penalidad' => (float) ($d->monto_penalidad ?? 0),
                        'monto_flete' => (float) ($d->monto_flete ?? 0),
                        'descuento' => (float) ($d->descuento ?? 0),
                        'subtotal_sin_descuento' => (float) ($d->subtotal_sin_descuento ?? $d->subtotal),
                        'subtotal' => (float) $d->subtotal,
                        'log_cambios' => $d->log_cambios ?? [],
                    ];
                })->toArray();
            })(),
            'transacciones_anticipo' => $item->transaccionesAnticipo->map(function ($t) {
                $ant = $t->anticipo;

                return [
                    'id' => $t->id,
                    'id_anticipo_proveedor' => $t->id_anticipo_proveedor,
                    'id_valorizacion_compra' => $t->id_valorizacion_compra,
                    'factura' => $ant ? ($ant->serie_factura.'-'.$ant->numero_factura) : null,
                    'monto_retirado' => (float) $t->monto_retirado,
                    'saldo_actual' => (float) $t->saldo_actual,
                    'estado' => $t->estado ? $t->estado->value : null,
                    'created_at' => $t->created_at ? $t->created_at->format('Y-m-d H:i:s') : null,
                    'log_cambios' => $t->log_cambios ?? [],
                ];
            })->toArray(),
        ];
    }

    /**
     * Obtener listado de valorizaciones
     */
    public static function get_valorizaciones(?int $idProveedor = null): array
    {
        $query = self::queryBase()->orderBy('id', 'desc');

        if ($idProveedor !== null && $idProveedor > 0) {
            $query->where('id_proveedor_minero', $idProveedor);
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, ValorizacionCompra> $items */
        $items = $query->get();

        return $items->map(function ($item) {
            /** @var ValorizacionCompra $item */
            return self::format_valorizacion($item);
        })->toArray();
    }

    /**
     * Obtener valorización individual atómicamente por ID
     */
    public static function get_valorizacion_by_id(int $id): ?array
    {
        $item = self::queryBase()->where('id', $id)->first();
        if (! $item) {
            return null;
        }

        return self::format_valorizacion($item);
    }

    /**
     * Buscar modelo ValorizacionCompra por ID
     */
    public static function find_model(int $id): ?ValorizacionCompra
    {
        return ValorizacionCompra::find($id);
    }

    /**
     * Buscar modelo ValorizacionCompra con relaciones necesarias para clonación y aprobación
     */
    public static function find_model_con_relaciones(int $id): ?ValorizacionCompra
    {
        return ValorizacionCompra::with(['detalles', 'transaccionesAnticipo', 'empleadoSolicitaOc'])->find($id);
    }

    /**
     * Crear registro de cabecera en valorizacion_compra
     *
     * @param  array<string,mixed>  $atributos
     */
    public static function crear_cabecera(array $atributos): ValorizacionCompra
    {
        return ValorizacionCompra::create($atributos);
    }

    /**
     * Actualizar campos de una cabecera ValorizacionCompra
     *
     * @param  array<string,mixed>  $atributos
     */
    public static function actualizar_cabecera(ValorizacionCompra $valorizacion, array $atributos): bool
    {
        return $valorizacion->update($atributos);
    }

    /**
     * Crear registro de detalle en valorizacion_compramineral_detalle
     *
     * @param  array<string,mixed>  $atributos
     */
    public static function crear_detalle(array $atributos): ValorizacionCompraDetalle
    {
        return ValorizacionCompraDetalle::create($atributos);
    }

    /**
     * Obtener el siguiente número de versión dentro de un uuid_grupo
     */
    public static function get_siguiente_version_por_grupo(string $uuidGrupo): int
    {
        $max = DB::table('valorizacion_compra')
            ->where('uuid_grupo', $uuidGrupo)
            ->max('numero_version');

        return ((int) ($max ?? 0)) + 1;
    }

    /**
     * Verificar si el grupo ya cuenta con una versión en estado Aprobado
     */
    public static function existe_version_aprobada_en_grupo(string $uuidGrupo, ?int $excluirId = null): bool
    {
        $query = DB::table('valorizacion_compra')
            ->where('uuid_grupo', $uuidGrupo)
            ->where('estado', EstadoValorizacionCompra::Aprobado->value);

        if ($excluirId !== null) {
            $query->where('id', '!=', $excluirId);
        }

        return $query->exists();
    }

    /**
     * Obtener las versiones hermanas en estado Propuesta de un mismo grupo para desestimarlas
     *
     * @return Collection<int, ValorizacionCompra>
     */
    public static function get_versiones_hermanas_propuesta(string $uuidGrupo, int $idExcluir): Collection
    {
        return ValorizacionCompra::with(['detalles', 'transaccionesAnticipo'])
            ->where('uuid_grupo', $uuidGrupo)
            ->where('id', '!=', $idExcluir)
            ->where('estado', EstadoValorizacionCompra::Propuesta->value)
            ->get();
    }

    /**
     * Obtener nombre o etiqueta de concesión
     */
    public static function get_nombre_concesion(int $idConcesion): string
    {
        $c = \Illuminate\Support\Facades\DB::table('concesion')->where('id', $idConcesion)->first();

        return $c ? $c->nombre : "ID #{$idConcesion}";
    }

    /**
     * Obtener etiqueta descriptiva de cuenta bancaria o de detracción
     */
    public static function get_etiqueta_cuenta(?int $idCuenta): string
    {
        if (! $idCuenta) {
            return '—';
        }
        $c = self::get_cuenta_bancaria_proveedor($idCuenta);
        if (! $c) {
            return "ID #{$idCuenta}";
        }
        $bancoStr = $c->banco_abrev ?: ($c->banco_nombre ?: '');

        return ! empty($bancoStr) ? "{$bancoStr} - {$c->numero_cuenta}" : $c->numero_cuenta;
    }

    /**
     * Obtener un grupo de análisis por ID
     */
    public static function get_grupo_analisis(int $id): ?object
    {
        return DB::table('grupo_analisis')->where('id', $id)->first();
    }

    /**
     * Actualizar campos de un grupo de análisis
     *
     * @param  array<string,mixed>  $campos
     */
    public static function actualizar_grupo_analisis(int $id, array $campos): void
    {
        DB::table('grupo_analisis')->where('id', $id)->update($campos);
    }

    /**
     * Activar un flag de propuesta sobre un grupo de análisis
     */
    public static function marcar_grupo_analisis(int $id, string $flag): void
    {
        DB::table('grupo_analisis')->where('id', $id)->update([$flag => 1]);
    }

    /**
     * Desactivar un flag de propuesta sobre un grupo de análisis
     */
    public static function desmarcar_grupo_analisis(int $id, string $flag): void
    {
        DB::table('grupo_analisis')->where('id', $id)->update([$flag => 0]);
    }

    /**
     * Obtener cuenta bancaria de proveedor junto con los datos de su banco
     */
    public static function get_cuenta_bancaria_proveedor(int $id): ?object
    {
        return DB::table('cuenta_bancaria_proveedor as cb')
            ->leftJoin('banco as b', 'b.id', '=', 'cb.id_banco')
            ->where('cb.id', $id)
            ->select('cb.numero_cuenta', 'b.nombre as banco_nombre', 'b.abreviatura as banco_abrev')
            ->first();
    }

    /**
     * Verificar si existe otra valorización viva (Propuesta o Aprobado) que valorice el mismo lote y elemento químico
     */
    public static function existe_otra_valorizacion_con_lote(
        int $idLoteMineral,
        string $elemento,
        int $idValorizacionActual,
        string $estadoAnulado = 'Anulado'
    ): bool {
        return DB::table('valorizacion_compramineral_detalle as vcd')
            ->join('valorizacion_compra as vc', 'vc.id', '=', 'vcd.id_valorizacion_compra')
            ->join('lote_guia as lg_check', 'lg_check.id', '=', 'vcd.id_lote_guia')
            ->leftJoin('particion_lote_mineral as plm', 'plm.id', '=', 'lg_check.id_particion_lote_mineral')
            ->whereRaw('COALESCE(lg_check.id_lote_mineral, plm.id_lote_mineral) = ?', [$idLoteMineral])
            ->whereIn('vc.estado', [
                EstadoValorizacionCompra::Propuesta->value,
                EstadoValorizacionCompra::Aprobado->value,
            ])
            ->where('vc.id', '!=', $idValorizacionActual)
            ->where('vcd.elemento_quimico', $elemento)
            ->exists();
    }

    /**
     * Verificar si un lote+elemento está bloqueado por otro grupo de valorización distinto (Propuesta o Aprobado)
     */
    public static function lote_bloqueado_en_otro_grupo(
        int $idLoteMineral,
        string $elemento,
        string $uuidGrupoActual,
        int $idValorizacionOrigen
    ): bool {
        return DB::table('valorizacion_compramineral_detalle as vcd')
            ->join('valorizacion_compra as vc', 'vc.id', '=', 'vcd.id_valorizacion_compra')
            ->join('lote_guia as lg_check', 'lg_check.id', '=', 'vcd.id_lote_guia')
            ->leftJoin('particion_lote_mineral as plm', 'plm.id', '=', 'lg_check.id_particion_lote_mineral')
            ->whereRaw('COALESCE(lg_check.id_lote_mineral, plm.id_lote_mineral) = ?', [$idLoteMineral])
            ->whereIn('vc.estado', [
                EstadoValorizacionCompra::Propuesta->value,
                EstadoValorizacionCompra::Aprobado->value,
            ])
            ->where('vc.id', '!=', $idValorizacionOrigen)
            ->where(function ($q) use ($uuidGrupoActual) {
                $q->whereNull('vc.uuid_grupo')
                    ->orWhere('vc.uuid_grupo', '!=', $uuidGrupoActual);
            })
            ->where('vcd.elemento_quimico', $elemento)
            ->exists();
    }

    /**
     * Verificar si existe otra valorización viva (Propuesta o Aprobado) que utilice el mismo grupo de análisis y elemento
     */
    public static function existe_otra_valorizacion_viva_con_grupo_analisis(
        int $idGrupoAnalisis,
        string $elemento,
        int $idValorizacionActual
    ): bool {
        return DB::table('valorizacion_compramineral_detalle as vcd')
            ->join('valorizacion_compra as vc', 'vc.id', '=', 'vcd.id_valorizacion_compra')
            ->where('vcd.id_grupo_analisis', $idGrupoAnalisis)
            ->where('vcd.elemento_quimico', $elemento)
            ->where('vc.id', '!=', $idValorizacionActual)
            ->whereIn('vc.estado', [
                EstadoValorizacionCompra::Propuesta->value,
                EstadoValorizacionCompra::Aprobado->value,
            ])
            ->exists();
    }

    /**
     * Marcar lotes minerales como valorizados para una columna específica
     *
     * @param  int[]  $idsLotes
     */
    public static function marcar_lotes_mineral_valorizados(array $idsLotes, string $columna): void
    {
        if (empty($idsLotes)) {
            return;
        }
        LoteMineral::whereIn('id', $idsLotes)->update([$columna => 1]);
    }

    /**
     * Desmarcar flag valorizado en un lote mineral
     */
    public static function desmarcar_lote_mineral_valorizado(int $idLote, string $columna): void
    {
        LoteMineral::where('id', $idLote)->update([$columna => 0]);
    }

    /**
     * Obtener un empleado por ID
     */
    public static function get_empleado(int $id): ?object
    {
        return DB::table('empleado')->where('id', $id)->first();
    }

    /**
     * Buscar lote guía con su relación de lote mineral
     */
    public static function find_lote_guia_con_mineral(int $idLoteGuia): ?\App\Models\LoteGuia
    {
        return \App\Models\LoteGuia::with([
            'loteMineral',
            'particionLoteMineral.loteMineral',
        ])->find($idLoteGuia);
    }

    /**
     * Buscar anticipo de proveedor por ID
     */
    public static function find_anticipo_proveedor(int $idAnticipo): ?\App\Models\AnticipoProveedor
    {
        return \App\Models\AnticipoProveedor::find($idAnticipo);
    }

    /**
     * Obtener detalles de una valorización por ID
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\ValorizacionCompraDetalle>
     */
    public static function get_detalles_by_valorizacion(int $idValorizacion)
    {
        return \App\Models\ValorizacionCompraDetalle::where('id_valorizacion_compra', $idValorizacion)->get();
    }

    /**
     * Obtener transacciones de anticipo de una valorización por ID
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\TransaccionAnticipoProveedor>
     */
    public static function get_transacciones_by_valorizacion(int $idValorizacion)
    {
        return \App\Models\TransaccionAnticipoProveedor::where('id_valorizacion_compra', $idValorizacion)->get();
    }

    /**
     * Eliminar detalles de una valorización por ID
     */
    public static function delete_detalles_by_valorizacion(int $idValorizacion): int
    {
        return \App\Models\ValorizacionCompraDetalle::where('id_valorizacion_compra', $idValorizacion)->delete();
    }

    /**
     * Eliminar transacciones de anticipo de una valorización por ID
     */
    public static function delete_transacciones_by_valorizacion(int $idValorizacion): int
    {
        return \App\Models\TransaccionAnticipoProveedor::where('id_valorizacion_compra', $idValorizacion)->delete();
    }

    /**
     * Anular una transacción de anticipo atómicamente con registro de auditoría
     */
    public static function anular_transaccion_anticipo(
        \App\Models\TransaccionAnticipoProveedor $transaccion,
        int $idEmpleadoAnulacion,
        string $codigoValorizacion
    ): void {
        $estadoAnteriorTrans = $transaccion->estado->value ?? '—';
        $logTrans = $transaccion->log_cambios ?? [];
        if (! is_array($logTrans)) {
            $logTrans = json_decode((string) $logTrans, true) ?? [];
        }
        $logTrans[] = RES_CambiosLog::crear(
            $idEmpleadoAnulacion,
            "Anulación de Transacción de Anticipo: Transacción anulada por anulación de Valorización {$codigoValorizacion}",
            [
                [
                    'campo_bd' => 'estado',
                    'campo' => 'Estado Transacción',
                    'valor_anterior' => $estadoAnteriorTrans,
                    'valor_nuevo' => \App\Shared\Enums\ValorizacionCompra\EstadoTransaccionAnticipo::Anulado->value,
                ],
            ]
        );

        $transaccion->update([
            'estado' => \App\Shared\Enums\ValorizacionCompra\EstadoTransaccionAnticipo::Anulado->value,
            'log_cambios' => $logTrans,
        ]);
    }

    /**
     * Eliminar un modelo ValorizacionCompra
     */
    public static function delete_model(ValorizacionCompra $valorizacion): ?bool
    {
        return $valorizacion->delete();
    }
}
