<?php

namespace App\Modules\ContabilidadCompra\Services;

use App\Models\ComprobanteCompra;
use App\Models\PagoComprobanteCompra;
use App\Models\TipoCambio;
use App\Models\ValorizacionCompra;
use App\Modules\ContabilidadCompra\Data\ContabilidadCompraData;
use App\Shared\Enums\_Generic\EstadoBase;
use App\Shared\Enums\ContabilidadCompra\EstadoComprobanteCompra;
use App\Shared\Enums\ContabilidadCompra\TipoAprobacionComprobante;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Responses\ApiResponse;
use Exception;
use Illuminate\Support\Facades\DB;

class ContabilidadCompraService
{
    /**
     * Listar comprobantes con filtros opcionales.
     */
    public static function listar_comprobantes(?int $idProveedor = null, ?string $estado = null, ?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        $data = ContabilidadCompraData::get_comprobantes($idProveedor, $estado, $fechaInicio, $fechaFin);

        return ApiResponse::success($data, 'Comprobantes obtenidos correctamente.');
    }

    /**
     * Obtener un comprobante por su ID con sus pagos y lotes valorizados.
     */
    public static function obtener_comprobante(int $id): array
    {
        $data = ContabilidadCompraData::get_comprobante_by_id($id);
        if (! $data) {
            return ApiResponse::error("Comprobante con ID {$id} no encontrado.");
        }

        return ApiResponse::success($data, 'Comprobante obtenido correctamente.');
    }

    /**
     * Crear un comprobante calculando todos los importes derivados a partir de la valorización
     * y del tipo de cambio de la fecha_emision.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<int,\Illuminate\Http\UploadedFile>  $archivosEvidencia
     */
    public static function crear_comprobante(array $payload, array $archivosEvidencia = []): array
    {
        DB::beginTransaction();
        try {
            $idValorizacion = (int) $payload['id_valorizacion_compra'];

            // 1. Validar que la valorización existe y está aprobada
            $valorizacion = ValorizacionCompra::find($idValorizacion);
            if (! $valorizacion) {
                return ApiResponse::error("La valorización con ID {$idValorizacion} no existe.");
            }
            if ($valorizacion->estado->value !== \App\Shared\Enums\ValorizacionCompra\EstadoValorizacionCompra::Aprobado->value) {
                return ApiResponse::error('Solo se pueden registrar comprobantes de valorizaciones aprobadas.');
            }

            // 2. Validar que no exista ya un comprobante para esta valorización
            if (ContabilidadCompraData::existe_comprobante_para_valorizacion($idValorizacion)) {
                return ApiResponse::error('Ya existe un comprobante activo para esta valorización.');
            }

            // 3. Resolver Tipo de Cambio por la fecha_emision
            $fechaEmision = (string) $payload['fecha_emision'];
            $tipoCambio = TipoCambio::where('fecha', $fechaEmision)
                ->where('estado', EstadoBase::Activo->value)
                ->first();
            if (! $tipoCambio) {
                return ApiResponse::error(
                    "No existe un tipo de cambio registrado para la fecha {$fechaEmision}. ".
                    'Registre primero el tipo de cambio de esa jornada.'
                );
            }

            $valorVenta = (float) $tipoCambio->valor_venta;

            // 4. Calcular importes derivados
            $porcentajeIgv = (float) ($payload['porcentaje_igv'] ?? 0.18);
            $porcentajeDetraccion = (float) ($payload['porcentaje_detraccion'] ?? 0.11);

            $importes = ContabilidadCompraData::calcular_importes(
                $idValorizacion,
                $valorVenta,
                $porcentajeIgv,
                $porcentajeDetraccion
            );

            // 5. Persistir evidencias (si las hay)
            $evidenciasGuardadas = ! empty($archivosEvidencia)
                ? ArchivoHelper::guardarArchivos('comprobantes', $archivosEvidencia)
                : [];

            // 6. Insertar comprobante
            $aprobacionesIniciales = ContabilidadCompraData::build_aprobaciones_iniciales();

            $idEmpleadoRegistro = (int) $payload['id_empleado_registro'];

            $campos = [
                'id_valorizacion_compra' => $idValorizacion,
                'id_tipo_cambio' => (int) $tipoCambio->id,
                'id_empleado_registro' => $idEmpleadoRegistro,
                'serie' => strtoupper(trim((string) $payload['serie'])),
                'numero' => trim((string) $payload['numero']),
                'fecha_emision' => $fechaEmision,
                'evidencias' => ! empty($evidenciasGuardadas) ? json_encode(array_values($evidenciasGuardadas)) : null,
                'tipo_cambio_venta' => $valorVenta,
                'porcentaje_igv' => $porcentajeIgv,
                'porcentaje_detraccion' => $porcentajeDetraccion,
            ] + $importes;

            $idComprobante = ContabilidadCompraData::crear_comprobante($campos, $aprobacionesIniciales);

            DB::commit();

            $detalle = ContabilidadCompraData::get_comprobante_by_id($idComprobante);

            return ApiResponse::success($detalle, 'Comprobante creado correctamente en estado En Espera.');
        } catch (Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al crear comprobante: '.$e->getMessage());
        }
    }

    /**
     * Otorgar una aprobación (idempotente). Recalcula el estado del comprobante.
     *
     * @param  array<string,mixed>  $payload  Contiene: 'tipo' (Contabilidad|Comercial|Documentaria)
     */
    public static function aprobar_comprobante(int $id, array $payload): array
    {
        DB::beginTransaction();
        try {
            $comprobante = ComprobanteCompra::find($id);
            if (! $comprobante) {
                return ApiResponse::error("Comprobante con ID {$id} no encontrado.");
            }

            if ($comprobante->estado->value === EstadoComprobanteCompra::Anulado->value) {
                return ApiResponse::error('El comprobante está anulado.');
            }

            $tipoStr = (string) ($payload['tipo'] ?? '');
            $tipoEnum = TipoAprobacionComprobante::tryFrom($tipoStr);
            if (! $tipoEnum) {
                return ApiResponse::error('Tipo de aprobación inválido. Use Contabilidad, Comercial o Documentaria.');
            }

            $idEmpleado = (int) ($payload['id_empleado'] ?? 0);
            if (! $idEmpleado) {
                return ApiResponse::error('No se pudo identificar el empleado autenticado.');
            }

            $aprobaciones = $comprobante->aprobaciones ?? [];
            $encontrado = false;
            foreach ($aprobaciones as $idx => $ap) {
                if (($ap['tipo'] ?? '') === $tipoEnum->value) {
                    if (($ap['esta_aprobado'] ?? false) === true) {
                        DB::commit();

                        return ApiResponse::success(
                            ContabilidadCompraData::get_comprobante_by_id($id),
                            'La aprobación ya estaba registrada.'
                        );
                    }
                    $aprobaciones[$idx]['esta_aprobado'] = true;
                    $aprobaciones[$idx]['id_empleado'] = $idEmpleado;
                    $aprobaciones[$idx]['created_at'] = now()->toDateTimeString();
                    $encontrado = true;
                    break;
                }
            }

            if (! $encontrado) {
                return ApiResponse::error('No se encontró la aprobación solicitada en el comprobante.');
            }

            // Recalcular estado del comprobante en función de las aprobaciones
            $nuevoEstado = self::resolver_estado_por_aprobaciones($comprobante, $aprobaciones);

            ContabilidadCompraData::actualizar_aprobaciones($id, $aprobaciones, $nuevoEstado);

            DB::commit();

            return ApiResponse::success(
                ContabilidadCompraData::get_comprobante_by_id($id),
                "Aprobación de {$tipoEnum->value} registrada correctamente."
            );
        } catch (Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al aprobar comprobante: '.$e->getMessage());
        }
    }

    /**
     * Anular un comprobante (cascada: anula también sus pagos vigentes).
     *
     * @param  array<string,mixed>  $payload  Contiene: 'motivo'
     */
    public static function anular_comprobante(int $id, array $payload): array
    {
        DB::beginTransaction();
        try {
            $comprobante = ComprobanteCompra::find($id);
            if (! $comprobante) {
                return ApiResponse::error("Comprobante con ID {$id} no encontrado.");
            }

            if ($comprobante->estado->value === EstadoComprobanteCompra::Anulado->value) {
                return ApiResponse::error('El comprobante ya se encuentra anulado.');
            }

            $motivo = trim((string) ($payload['motivo'] ?? ''));
            if ($motivo === '') {
                return ApiResponse::error('El motivo de anulación es obligatorio.');
            }

            $idEmpleadoAnulacion = (int) ($payload['id_empleado_anulacion'] ?? 0);
            if (! $idEmpleadoAnulacion) {
                return ApiResponse::error('No se pudo identificar el empleado autenticado.');
            }

            $pagosAnulados = ContabilidadCompraData::anular_pagos_del_comprobante($id, $idEmpleadoAnulacion, $motivo);

            ContabilidadCompraData::anular_comprobante($id, $idEmpleadoAnulacion, $motivo);

            DB::commit();

            $detalle = ContabilidadCompraData::get_comprobante_by_id($id);
            $detalle->pagos_anulados_en_cascada = $pagosAnulados;

            return ApiResponse::success(
                $detalle,
                "Comprobante anulado. {$pagosAnulados} pago(s) asociado(s) también fueron anulados."
            );
        } catch (Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al anular comprobante: '.$e->getMessage());
        }
    }

    /**
     * Registrar un pago sobre un comprobante. Valida que las 3 aprobaciones estén dadas.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<int,\Illuminate\Http\UploadedFile>  $archivosEvidencia
     */
    public static function registrar_pago(int $idComprobante, array $payload, array $archivosEvidencia = []): array
    {
        DB::beginTransaction();
        try {
            $comprobante = ComprobanteCompra::find($idComprobante);
            if (! $comprobante) {
                return ApiResponse::error("Comprobante con ID {$idComprobante} no encontrado.");
            }

            if ($comprobante->estado->value === EstadoComprobanteCompra::Anulado->value) {
                return ApiResponse::error('No se pueden registrar pagos sobre un comprobante anulado.');
            }

            $todasAprobadas = collect($comprobante->aprobaciones ?? [])->every(fn ($a) => ($a['esta_aprobado'] ?? false) === true);
            if (! $todasAprobadas) {
                return ApiResponse::error('Debe completar las 3 aprobaciones antes de registrar pagos.');
            }

            $esParaDetraccion = (bool) ($payload['es_para_detraccion'] ?? false);
            $montoPagado = (float) ($payload['monto_pagado'] ?? 0);

            // Validar saldo pendiente
            $saldo = $esParaDetraccion
                ? (float) $comprobante->monto_detraccion_soles - (float) $comprobante->avance_pago_detraccion
                : (float) $comprobante->monto_neto - (float) $comprobante->avance_pago_neto;

            if ($montoPagado <= 0) {
                return ApiResponse::error('El monto pagado debe ser mayor a 0.');
            }

            if ($montoPagado > $saldo + 0.0001) {
                return ApiResponse::error("El monto del pago ({$montoPagado}) excede el saldo pendiente ({$saldo}).");
            }

            $evidenciasGuardadas = ! empty($archivosEvidencia)
                ? ArchivoHelper::guardarArchivos('pagos-comprobantes', $archivosEvidencia)
                : [];

            $medioPago = ContabilidadCompraData::parse_medio_pago((string) ($payload['medio_pago'] ?? 'Transferencia'))
                ?? \App\Shared\Enums\ContabilidadCompra\MedioPagoComprobante::Transferencia;

            $idEmpleadoRegistro = (int) ($payload['id_empleado_registro'] ?? 0);
            $fechaHoraPago = $payload['fecha_hora_pago'] ?? now()->toDateTimeString();

            $idPago = PagoComprobanteCompra::insertGetId([
                'id_comprobante_compra' => $idComprobante,
                'id_cuenta_bancaria_empresa' => isset($payload['id_cuenta_bancaria_empresa']) ? (int) $payload['id_cuenta_bancaria_empresa'] : null,
                'id_cuenta_bancaria_proveedor' => isset($payload['id_cuenta_bancaria_proveedor']) ? (int) $payload['id_cuenta_bancaria_proveedor'] : null,
                'id_empleado_registro' => $idEmpleadoRegistro,
                'id_empleado_anulacion' => null,
                'es_para_detraccion' => $esParaDetraccion,
                'medio_pago' => $medioPago->value,
                'monto_pagado' => round($montoPagado, 2),
                'fecha_hora_pago' => $fechaHoraPago,
                'numero_operacion' => $payload['numero_operacion'] ?? null,
                'observacion' => $payload['observacion'] ?? null,
                'evidencias' => ! empty($evidenciasGuardadas) ? json_encode(array_values($evidenciasGuardadas)) : null,
                'fecha_hora_anulacion' => null,
                'motivo_anulacion' => null,
                'evidencias_anulacion' => null,
                'es_anulado' => false,
                'created_at' => now(),
            ]);

            // Actualizar avance_pago_*
            if ($esParaDetraccion) {
                $comprobante->avance_pago_detraccion = round((float) $comprobante->avance_pago_detraccion + $montoPagado, 2);
            } else {
                $comprobante->avance_pago_neto = round((float) $comprobante->avance_pago_neto + $montoPagado, 2);
            }

            // Verificar si ya quedó completamente pagado (transición a Pagado)
            $nuevoEstado = self::resolver_estado_por_pagos($comprobante);
            if ($nuevoEstado !== null) {
                $comprobante->estado = $nuevoEstado;
            }
            $comprobante->save();

            DB::commit();

            $detallePago = DB::table('pago_comprobante_compra')
                ->leftJoin('empleado as emp_reg', 'emp_reg.id', '=', 'pago_comprobante_compra.id_empleado_registro')
                ->leftJoin('cuenta_bancaria_empresa as cn', 'cn.id', '=', 'pago_comprobante_compra.id_cuenta_bancaria_empresa')
                ->leftJoin('banco as bco_emp', 'bco_emp.id', '=', 'cn.id_banco')
                ->leftJoin('cuenta_bancaria_proveedor as cp', 'cp.id', '=', 'pago_comprobante_compra.id_cuenta_bancaria_proveedor')
                ->leftJoin('banco as bco_prov', 'bco_prov.id', '=', 'cp.id_banco')
                ->where('pago_comprobante_compra.id', $idPago)
                ->select(
                    'pago_comprobante_compra.*',
                    DB::raw("CONCAT(emp_reg.nombre, ' ', emp_reg.apellido) AS empleado_registro_nombre"),
                    'bco_emp.nombre as banco_empresa_nombre',
                    'cn.numero_cuenta as empresa_numero_cuenta',
                    'cn.moneda as empresa_moneda',
                    'bco_prov.nombre as banco_proveedor_nombre',
                    'cp.numero_cuenta as proveedor_numero_cuenta',
                    'cp.moneda as proveedor_moneda'
                )
                ->first();

            return ApiResponse::success($detallePago, 'Pago registrado correctamente.');
        } catch (Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al registrar pago: '.$e->getMessage());
        }
    }

    /**
     * Anular un pago (revierte el avance_pago_ y posiblemente el estado del comprobante).
     *
     * @param  array<string,mixed>  $payload  Contiene: 'motivo', 'evidencias_anulacion' opcional
     * @param  array<int,\Illuminate\Http\UploadedFile>  $archivosEvidencia
     */
    public static function anular_pago(int $idPago, array $payload, array $archivosEvidencia = []): array
    {
        DB::beginTransaction();
        try {
            $pago = PagoComprobanteCompra::find($idPago);
            if (! $pago) {
                return ApiResponse::error("Pago con ID {$idPago} no encontrado.");
            }
            if ($pago->es_anulado) {
                return ApiResponse::error('El pago ya se encuentra anulado.');
            }

            $motivo = trim((string) ($payload['motivo'] ?? ''));
            if ($motivo === '') {
                return ApiResponse::error('El motivo de anulación es obligatorio.');
            }

            $idEmpleadoAnulacion = (int) ($payload['id_empleado_anulacion'] ?? 0);
            if (! $idEmpleadoAnulacion) {
                return ApiResponse::error('No se pudo identificar el empleado autenticado.');
            }

            $evidenciasAnulacionGuardadas = ! empty($archivosEvidencia)
                ? ArchivoHelper::guardarArchivos('pagos-comprobantes/anulaciones', $archivosEvidencia)
                : [];

            $pago->update([
                'es_anulado' => true,
                'id_empleado_anulacion' => $idEmpleadoAnulacion,
                'fecha_hora_anulacion' => now(),
                'motivo_anulacion' => $motivo,
                'evidencias_anulacion' => ! empty($evidenciasAnulacionGuardadas) ? array_values($evidenciasAnulacionGuardadas) : null,
            ]);

            // Devolver el monto al comprobante
            $comprobante = ComprobanteCompra::find($pago->id_comprobante_compra);
            if ($comprobante) {
                $monto = (float) $pago->monto_pagado;
                if ($pago->es_para_detraccion) {
                    $comprobante->avance_pago_detraccion = max(0, round((float) $comprobante->avance_pago_detraccion - $monto, 2));
                } else {
                    $comprobante->avance_pago_neto = max(0, round((float) $comprobante->avance_pago_neto - $monto, 2));
                }
                // Si estaba en Pagado y al revertir queda incompleto, volver a En Proceso
                if ($comprobante->estado->value === EstadoComprobanteCompra::Pagado->value) {
                    $comprobante->estado = EstadoComprobanteCompra::EnProceso;
                }
                $comprobante->save();
            }

            DB::commit();

            return ApiResponse::success(
                PagoComprobanteCompra::find($idPago),
                'Pago anulado correctamente. El monto ha sido devuelto al comprobante.'
            );
        } catch (Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al anular pago: '.$e->getMessage());
        }
    }

    /**
     * Listar los pagos de un comprobante.
     */
    public static function listar_pagos(int $idComprobante): array
    {
        $rows = ContabilidadCompraData::get_pagos_by_comprobante($idComprobante);

        return ApiResponse::success($rows, 'Pagos obtenidos correctamente.');
    }

    /**
     * Calcular el estado del comprobante en función de sus aprobaciones.
     * Devuelve null si debe quedarse en el estado actual.
     */
    private static function resolver_estado_por_aprobaciones(ComprobanteCompra $comprobante, array $aprobaciones): ?EstadoComprobanteCompra
    {
        $todas = collect($aprobaciones)->every(fn ($a) => ($a['esta_aprobado'] ?? false) === true);
        $alguna = collect($aprobaciones)->contains(fn ($a) => ($a['esta_aprobado'] ?? false) === true);

        $actual = $comprobante->estado->value;

        if ($todas) {
            if ($actual === EstadoComprobanteCompra::Pagado->value) {
                return null;
            }

            return EstadoComprobanteCompra::EnProceso;
        }

        if ($alguna) {
            if ($actual !== EstadoComprobanteCompra::EnEspera->value) {
                return null;
            }

            return EstadoComprobanteCompra::EnProceso;
        }

        return null;
    }

    /**
     * Si las 3 aprobaciones están dadas y los pagos cubren el neto + la detracción,
     * promover a Pagado. Devuelve null si no hay cambio.
     */
    private static function resolver_estado_por_pagos(ComprobanteCompra $comprobante): ?EstadoComprobanteCompra
    {
        if ($comprobante->estado->value === EstadoComprobanteCompra::Anulado->value) {
            return null;
        }

        $aprobaciones = $comprobante->aprobaciones ?? [];
        $todasAprobadas = collect($aprobaciones)->every(fn ($a) => ($a['esta_aprobado'] ?? false) === true);
        if (! $todasAprobadas) {
            return null;
        }

        $netoCompleto = round((float) $comprobante->monto_neto - (float) $comprobante->avance_pago_neto, 2) <= 0.01;
        $detraccionCompleta = round((float) $comprobante->monto_detraccion_soles - (float) $comprobante->avance_pago_detraccion, 2) <= 0.01;

        if ($netoCompleto && $detraccionCompleta) {
            return EstadoComprobanteCompra::Pagado;
        }

        return null;
    }
}
