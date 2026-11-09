<?php

namespace App\Modules\ContabilidadCompra\Services;

use App\Models\ComprobanteCompra;
use App\Models\ComprobanteNota;
use App\Modules\ContabilidadCompra\Data\ComprobanteNotaData;
use App\Modules\ContabilidadCompra\Data\ContabilidadCompraData;
use App\Shared\Enums\_Generic\Periodo;
use App\Shared\Enums\ContabilidadCompra\EstadoComprobanteCompra;
use App\Shared\Enums\ContabilidadCompra\MotivoNotaCreditoComprobante;
use App\Shared\Enums\ContabilidadCompra\MotivoNotaDebitoComprobante;
use App\Shared\Enums\ContabilidadCompra\TipoNotaComprobante;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Helpers\CorrelativoHelper;
use App\Shared\Responses\ApiResponse;
use Exception;
use Illuminate\Support\Facades\DB;

class ComprobanteNotaService
{
    /**
     * Listar las notas de un comprobante.
     */
    public static function listar_notas(int $idComprobante): array
    {
        $comprobante = ComprobanteCompra::find($idComprobante);
        if (! $comprobante) {
            return ApiResponse::error("Comprobante con ID {$idComprobante} no encontrado.");
        }

        $notas = ComprobanteNotaData::get_notas_by_comprobante($idComprobante);

        return ApiResponse::success($notas, 'Notas del comprobante obtenidas correctamente.');
    }

    /**
     * Registrar una nota de crédito o débito sobre un comprobante.
     *
     * La nota NO altera los montos reales del comprobante (`total`,
     * `monto_neto`, `monto_detraccion_soles`). El monto ajustado se persiste en
     * `monto_neto_final` / `monto_detraccion_soles_final`.
     *
     * Si es la PRIMERA nota del comprobante, la valorización asociada queda
     * marcada como dirimencia y recibe su propio correlativo (DRD-AA-NNNNN),
     * sin que ese cambio aparezca en `log_cambios`.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<int,\Illuminate\Http\UploadedFile>  $archivosEvidencia
     */
    public static function registrar_nota(int $idComprobante, array $payload, array $archivosEvidencia = []): array
    {
        // --- VALIDACIONES (fuera de la transacción) ---
        $comprobante = ComprobanteCompra::find($idComprobante);
        if (! $comprobante) {
            return ApiResponse::error("Comprobante con ID {$idComprobante} no encontrado.");
        }

        if ($comprobante->estado->value === EstadoComprobanteCompra::Anulado->value) {
            return ApiResponse::error('No se pueden emitir notas sobre un comprobante anulado.');
        }

        // Comprobantes de valorizaciones sin valor comercial: no hay monto que ajustar.
        if ((float) $comprobante->total <= 0) {
            return ApiResponse::error('No se pueden emitir notas sobre un comprobante sin valor comercial.');
        }

        $tipo = TipoNotaComprobante::tryFrom((string) ($payload['tipo'] ?? ''));
        if (! $tipo) {
            return ApiResponse::error('Tipo de nota inválido. Use Credito o Debito.');
        }

        $monto = round((float) ($payload['monto'] ?? 0), 2);
        if ($monto <= 0) {
            return ApiResponse::error('El monto de la nota debe ser mayor a 0.');
        }

        // Un comprobante no puede tener notas de crédito Y de débito vigentes a la vez.
        // Las notas anuladas no cuentan: el comprobante vuelve a estar disponible.
        $tipoContrario = $tipo === TipoNotaComprobante::Credito
            ? TipoNotaComprobante::Debito
            : TipoNotaComprobante::Credito;

        if (ComprobanteNotaData::existe_nota_vigente_de_tipo($idComprobante, $tipoContrario->value)) {
            return ApiResponse::error(
                "El comprobante ya tiene una {$tipoContrario->descripcion()} vigente. "
                .'No se pueden registrar notas de crédito y de débito en el mismo comprobante.'
            );
        }

        $codigoMotivo = trim((string) ($payload['motivo'] ?? ''));
        if (! self::motivoValidoParaTipo($tipo, $codigoMotivo)) {
            return ApiResponse::error(
                "El motivo '{$codigoMotivo}' no corresponde al catálogo SUNAT de {$tipo->descripcion()}."
            );
        }

        $fechaEmision = (string) ($payload['fecha_emision'] ?? '');
        $fechaComprobante = $comprobante->fecha_emision instanceof \DateTimeInterface
            ? $comprobante->fecha_emision->format('Y-m-d')
            : (string) $comprobante->fecha_emision;
        if ($fechaEmision < $fechaComprobante) {
            return ApiResponse::error('La fecha de emisión de la nota no puede ser anterior a la del comprobante.');
        }

        $codigoNota = trim((string) ($payload['codigo_nota'] ?? ''));
        if ($codigoNota !== '' && ComprobanteNotaData::existe_codigo_nota($idComprobante, $codigoNota)) {
            return ApiResponse::error("Ya existe una nota con el código '{$codigoNota}' en este comprobante.");
        }

        $montoNeto = (float) $comprobante->monto_neto;
        $deltaActual = ComprobanteNotaData::sumar_delta_neto($idComprobante);
        $montoNetoFinal = round($montoNeto + $deltaActual + ($tipo->signo() * $monto), 2);

        // No se puede credited más de lo facturado: el neto ajustado no puede ser negativo.
        if ($montoNetoFinal < 0) {
            return ApiResponse::error('El monto de la nota excede el monto neto del comprobante.');
        }

        // La nota se puede emitir en cualquier estado del comprobante salvo Anulado.
        // Si el monto ya pagado supera el neto ajustado, queda un SALDO A FAVOR
        // (el proveedor devuelve el excedente): no bloquea la emisión.

        // --- ESCRITURAS (dentro de la transacción) ---
        DB::beginTransaction();
        try {
            $documentosGuardados = ! empty($archivosEvidencia)
                ? ArchivoHelper::guardarArchivos('notas-comprobantes', $archivosEvidencia)
                : [];

            $idNota = ComprobanteNotaData::insertar_nota([
                'id_comprobante_compra' => $idComprobante,
                'id_empleado_registro' => (int) ($payload['id_empleado_registro'] ?? 0),
                'tipo' => $tipo->value,
                'fecha_emision' => $fechaEmision,
                'codigo_nota' => $codigoNota !== '' ? $codigoNota : null,
                'motivo' => $codigoMotivo,
                'monto' => $monto,
                'documentos' => ! empty($documentosGuardados) ? json_encode(array_values($documentosGuardados)) : null,
            ]);

            // Recalcular el snapshot de montos ajustados
            ComprobanteNotaData::recalcular_montos_finales($idComprobante);

            // Primera nota → la valorización entra en dirimencia (correlativo propio)
            $dirimenciaAsignada = false;
            $correlativoDirimencia = null;
            if (ComprobanteNotaData::contar_notas_vigentes($idComprobante) === 1) {
                $dirimenciaAsignada = self::asignar_dirimencia((int) $comprobante->id_valorizacion_compra);
                $valorizacion = ComprobanteNotaData::get_valorizacion_de_comprobante($idComprobante);
                $correlativoDirimencia = $valorizacion?->correlativo_dirimencia;
            }

            // Re-evaluar el estado del comprobante con el monto neto ajustado
            $comprobante->refresh();
            $nuevoEstado = ContabilidadCompraService::resolver_estado_por_pagos($comprobante);
            if ($nuevoEstado !== null) {
                $comprobante->estado = $nuevoEstado;
                $comprobante->save();
            }

            DB::commit();

            $detalle = ContabilidadCompraData::get_comprobante_by_id($idComprobante);
            $notas = ComprobanteNotaData::get_notas_by_comprobante($idComprobante);

            $mensaje = $dirimenciaAsignada
                ? "Nota de {$tipo->descripcion()} registrada. La valorización {$correlativoDirimencia} quedó en dirimencia."
                : "Nota de {$tipo->descripcion()} registrada correctamente.";

            return ApiResponse::success([
                'nota' => collect($notas)->firstWhere('id', $idNota),
                'comprobante' => $detalle,
            ], $mensaje);
        } catch (Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al registrar la nota: '.$e->getMessage());
        }
    }

    /**
     * Anular una nota de crédito o débito.
     *
     * La anulación recalcula el snapshot de montos ajustados. El correlativo de
     * dirimencia NO se revierte: un correlativo ya emitido es un documento.
     *
     * @param  array<string,mixed>  $payload
     */
    public static function anular_nota(int $idNota, array $payload): array
    {
        // --- VALIDACIONES (fuera de la transacción) ---
        $nota = ComprobanteNota::find($idNota);
        if (! $nota) {
            return ApiResponse::error("Nota con ID {$idNota} no encontrada.");
        }

        if ($nota->es_anulado) {
            return ApiResponse::error('La nota ya se encuentra anulada.');
        }

        $motivo = trim((string) ($payload['motivo'] ?? ''));
        if ($motivo === '') {
            return ApiResponse::error('El motivo de anulación es obligatorio.');
        }

        $idEmpleadoAnulacion = (int) ($payload['id_empleado_anulacion'] ?? 0);
        if (! $idEmpleadoAnulacion) {
            return ApiResponse::error('No se pudo identificar el empleado autenticado.');
        }

        $idComprobante = (int) $nota->id_comprobante_compra;

        // --- ESCRITURAS (dentro de la transacción) ---
        DB::beginTransaction();
        try {
            ComprobanteNotaData::anular_nota($idNota, $idEmpleadoAnulacion, $motivo);
            ComprobanteNotaData::recalcular_montos_finales($idComprobante);

            $comprobante = ComprobanteCompra::find($idComprobante);
            if ($comprobante) {
                $nuevoEstado = ContabilidadCompraService::resolver_estado_por_pagos($comprobante);
                if ($nuevoEstado !== null) {
                    $comprobante->estado = $nuevoEstado;
                    $comprobante->save();
                }
            }

            DB::commit();

            return ApiResponse::success(
                [
                    'nota' => ComprobanteNota::find($idNota),
                    'comprobante' => ContabilidadCompraData::get_comprobante_by_id($idComprobante),
                ],
                'Nota anulada correctamente. Se recalcularon los montos ajustados del comprobante.'
            );
        } catch (Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al anular la nota: '.$e->getMessage());
        }
    }

    /**
     * Asignar el correlativo de dirimencia a la valorización.
     *
     * Solo la primera nota del comprobante lo asigna: el UPDATE es condicional
     * (`con_dirimencia = 0`). No escribe en `log_cambios`.
     */
    private static function asignar_dirimencia(int $idValorizacion): bool
    {
        if (! $idValorizacion) {
            return false;
        }

        $correlativo = CorrelativoHelper::generar(
            tabla: 'valorizacion_compra',
            prefijo: 'DRD',
            filtros: [],
            longitudCeros: 5,
            reseteo: Periodo::Anual,
            columnaFecha: 'fecha_hora_dirimencia',
            formatoFecha: 'y',
            prefijoPrimero: true,
            columnaNumero: 'numero_correlativo_dirimencia',
        );

        return ComprobanteNotaData::marcar_dirimencia_valorizacion(
            $idValorizacion,
            (string) $correlativo['correlativo'],
            (int) $correlativo['numero_correlativo']
        );
    }

    /**
     * Verificar que el motivo pertenezca al catálogo SUNAT del tipo de nota.
     */
    private static function motivoValidoParaTipo(TipoNotaComprobante $tipo, string $codigo): bool
    {
        if ($codigo === '') {
            return false;
        }

        return $tipo === TipoNotaComprobante::Credito
            ? MotivoNotaCreditoComprobante::tryFrom($codigo) !== null
            : MotivoNotaDebitoComprobante::tryFrom($codigo) !== null;
    }
}
