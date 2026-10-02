<?php

namespace App\Modules\ContabilidadVenta\Services;

use App\Models\ComprobanteVenta;
use App\Models\Empresa;
use App\Models\TipoCambio;
use App\Models\ValorizacionVentaDetalle;
use App\Modules\ContabilidadVenta\Data\ContabilidadVentaData;
use App\Shared\Enums\ContabilidadVenta\EstadoComprobanteVenta;
use App\Shared\Enums\ContabilidadVenta\MedioPagoComprobanteVenta;
use App\Shared\Enums\ContabilidadVenta\TipoPagoComprobanteVenta;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Responses\ApiResponse;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class ContabilidadVentaService
{
    /**
     * Listar comprobantes de venta con filtros opcionales.
     */
    public static function listar_comprobantes(?int $idPlanta = null, ?string $estado = null, ?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        $data = ContabilidadVentaData::get_comprobantes($idPlanta, $estado, $fechaInicio, $fechaFin);

        return ApiResponse::success($data);
    }

    /**
     * Obtener comprobante de venta por ID.
     */
    public static function obtener_comprobante(int $id): array
    {
        $data = ContabilidadVentaData::get_comprobante_by_id($id);
        if (! $data) {
            return ApiResponse::error('Comprobante de venta no encontrado.', 404);
        }

        return ApiResponse::success($data);
    }

    /**
     * Obtener detalles de valorización de venta disponibles para una planta.
     */
    public static function get_detalles_disponibles(int $idPlanta): array
    {
        $data = ContabilidadVentaData::get_detalles_valorizacion_disponibles($idPlanta);

        return ApiResponse::success($data);
    }

    /**
     * Obtener anticipos activos con saldo para una planta.
     */
    public static function get_anticipos_disponibles(int $idPlanta): array
    {
        $data = ContabilidadVentaData::get_anticipos_disponibles($idPlanta);

        return ApiResponse::success($data);
    }

    /**
     * Crear un nuevo comprobante de venta.
     *
     * @param  array<string,mixed>  $payload
     * @param  UploadedFile[]  $archivosEvidencia
     */
    public static function crear_comprobante(array $payload, array $archivosEvidencia = []): array
    {
        DB::beginTransaction();
        try {
            $idPlanta = (int) ($payload['id_planta_destino'] ?? 0);
            if ($idPlanta <= 0) {
                DB::rollBack();

                return ApiResponse::error('Debe seleccionar una planta destino válida.');
            }

            // Autoelegir Fabero si no viene id_empresa
            $idEmpresa = ! empty($payload['id_empresa']) ? (int) $payload['id_empresa'] : 0;
            if ($idEmpresa <= 0) {
                $faberoEmpresa = Empresa::where('razon_social', 'like', '%Fabero%')->first() ?? Empresa::first();
                $idEmpresa = $faberoEmpresa ? (int) $faberoEmpresa->id : 1;
            }

            $detallesIds = $payload['detalles_ids'] ?? [];
            if (! is_array($detallesIds) || empty($detallesIds)) {
                DB::rollBack();

                return ApiResponse::error('Debe seleccionar al menos un lote valorizado de venta.');
            }

            // Verificar que no se hayan asignado ya a otro comprobante activo
            $usados = DB::table('detalle_comprobante_venta as dcv')
                ->join('comprobante_venta as cv', 'cv.id', '=', 'dcv.id_comprobante_venta')
                ->whereIn('dcv.id_valorizacion_venta_detalle', $detallesIds)
                ->where('cv.estado', '!=', EstadoComprobanteVenta::Anulado->value)
                ->count();

            if ($usados > 0) {
                DB::rollBack();

                return ApiResponse::error('Uno o más lotes valorizados seleccionados ya forman parte de un comprobante activo.');
            }

            // Sumar subtotales reales desde la BD para garantizar integridad
            $totalDolaresAntesDescuento = (float) ValorizacionVentaDetalle::whereIn('id', $detallesIds)->sum('subtotal');
            if ($totalDolaresAntesDescuento <= 0) {
                DB::rollBack();

                return ApiResponse::error('El subtotal de los lotes seleccionados debe ser mayor a cero.');
            }

            $idTipoCambio = (int) ($payload['id_tipo_cambio'] ?? 0);
            $tcRow = TipoCambio::find($idTipoCambio);
            if (! $tcRow) {
                DB::rollBack();

                return ApiResponse::error('El tipo de cambio indicado no existe.');
            }

            $tipoCambioVenta = (float) $tcRow->valor_venta;
            $percentajeIgv = isset($payload['percentaje_igv']) ? (float) $payload['percentaje_igv'] : 0.18;
            $porcentajeDetraccion = isset($payload['porcentaje_detraccion']) ? (float) $payload['porcentaje_detraccion'] : 0.11;

            $montoPenalidad = isset($payload['monto_penalidad']) ? (float) $payload['monto_penalidad'] : 0.0;
            $montoFlete = isset($payload['monto_flete']) ? (float) $payload['monto_flete'] : 0.0;
            $descuento = round($montoPenalidad + $montoFlete, 2);

            $totalDolares = max(round($totalDolaresAntesDescuento - $descuento, 2), 0.0);
            $totalSolesAntesDescuento = round($totalDolaresAntesDescuento * $tipoCambioVenta, 2);
            $totalSoles = max(round($totalDolares * $tipoCambioVenta, 2), 0.0);
            $montoIgvSoles = round($totalSoles * $percentajeIgv, 2);

            // Anticipos
            $anticiposItems = $payload['anticipos'] ?? [];
            $montoPagadoAnticipos = 0.0;
            if (is_array($anticiposItems)) {
                foreach ($anticiposItems as $ant) {
                    $montoPagadoAnticipos += (float) ($ant['monto_retirado'] ?? 0);
                }
            }
            $montoPagadoAnticipos = round($montoPagadoAnticipos, 2);

            $baseDetraccion = max(round($totalDolares - $montoPagadoAnticipos, 2), 0.0);
            $montoDetraccion = round($baseDetraccion * $porcentajeDetraccion, 2);
            $montoDetraccionSoles = round($montoDetraccion * $tipoCambioVenta, 2);
            $montoNeto = max(round($totalDolares - $montoPagadoAnticipos - $montoDetraccion, 2), 0.0);

            // Determinar tipo de pago
            if ($montoPagadoAnticipos <= 0.001) {
                $tipoPago = TipoPagoComprobanteVenta::Transferencia;
            } elseif ($montoNeto <= 0.001) {
                $tipoPago = TipoPagoComprobanteVenta::Anticipo;
            } else {
                $tipoPago = TipoPagoComprobanteVenta::Mixto;
            }

            // Archivos adjuntos
            $evidenciasGuardadas = ! empty($archivosEvidencia)
                ? ArchivoHelper::guardarArchivos('comprobantes_venta', $archivosEvidencia)
                : [];

            $campos = [
                'id_empresa' => $idEmpresa,
                'id_planta_destino' => $idPlanta,
                'id_tipo_cambio' => $idTipoCambio,
                'id_empleado_registro' => (int) ($payload['id_empleado_registro'] ?? auth()->id() ?? 1),
                'id_empleado_anulacion' => null,
                'tipo_pago' => $tipoPago->value,
                'codigo_comprobante' => trim((string) ($payload['codigo_comprobante'] ?? '')),
                'fecha_emision' => (string) ($payload['fecha_emision'] ?? date('Y-m-d')),
                'evidencias' => json_encode($evidenciasGuardadas),
                'tipo_cambio_venta' => $tipoCambioVenta,
                'percentaje_igv' => $percentajeIgv,
                'porcentaje_detraccion' => $porcentajeDetraccion,
                'total_dolares_antes_descuento' => round($totalDolaresAntesDescuento, 2),
                'total_soles_antes_descuento' => round($totalSolesAntesDescuento, 2),
                'descuento' => $descuento,
                'total_dolares' => $totalDolares,
                'total_soles' => $totalSoles,
                'monto_igv_soles' => $montoIgvSoles,
                'monto_pagado_anticipos' => $montoPagadoAnticipos,
                'monto_detraccion' => $montoDetraccion,
                'monto_detraccion_soles' => $montoDetraccionSoles,
                'monto_neto' => $montoNeto,
            ];

            $idEmpleado = (int) ($payload['id_empleado_registro'] ?? auth()->id() ?? 1);
            $idComprobante = ContabilidadVentaData::crear_comprobante($campos, $detallesIds, $anticiposItems, $idEmpleado);

            DB::commit();

            $detalle = ContabilidadVentaData::get_comprobante_by_id($idComprobante);

            return ApiResponse::success($detalle, 'Comprobante de venta creado exitosamente.');
        } catch (Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al registrar comprobante de venta: '.$e->getMessage());
        }
    }

    /**
     * Anular un comprobante de venta.
     *
     * @param  array<string,mixed>  $payload
     * @param  UploadedFile[]  $archivosEvidencia
     */
    public static function anular_comprobante(int $id, array $payload, array $archivosEvidencia = []): array
    {
        DB::beginTransaction();
        try {
            $comprobante = ComprobanteVenta::find($id);
            if (! $comprobante) {
                DB::rollBack();

                return ApiResponse::error('Comprobante de venta no encontrado.', 404);
            }

            if ($comprobante->estado?->value === EstadoComprobanteVenta::Anulado->value || $comprobante->estado === 'Anulado') {
                DB::rollBack();

                return ApiResponse::error('El comprobante de venta ya se encuentra anulado.');
            }

            $motivo = trim((string) ($payload['motivo'] ?? ''));
            if (empty($motivo)) {
                DB::rollBack();

                return ApiResponse::error('El motivo de anulación es obligatorio.');
            }

            $idEmpleadoAnulacion = (int) ($payload['id_empleado_anulacion'] ?? auth()->id() ?? 1);
            $evidenciasGuardadas = ! empty($archivosEvidencia)
                ? ArchivoHelper::guardarArchivos('comprobantes_venta/anulaciones', $archivosEvidencia)
                : null;

            ContabilidadVentaData::anular_comprobante($id, $idEmpleadoAnulacion, $motivo, $evidenciasGuardadas);

            DB::commit();

            $detalle = ContabilidadVentaData::get_comprobante_by_id($id);

            return ApiResponse::success($detalle, 'Comprobante de venta anulado exitosamente.');
        } catch (Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al anular comprobante: '.$e->getMessage());
        }
    }

    /**
     * Listar pagos de un comprobante.
     */
    public static function listar_pagos(int $idComprobante): array
    {
        $rows = ContabilidadVentaData::get_pagos_by_comprobante($idComprobante);

        return ApiResponse::success($rows);
    }

    /**
     * Registrar un nuevo pago (Planta Destino paga a Empresa Fabero).
     *
     * @param  array<string,mixed>  $payload
     * @param  UploadedFile[]  $archivosEvidencia
     */
    public static function registrar_pago(int $idComprobante, array $payload, array $archivosEvidencia = []): array
    {
        DB::beginTransaction();
        try {
            $comprobante = ComprobanteVenta::find($idComprobante);
            if (! $comprobante) {
                DB::rollBack();

                return ApiResponse::error('Comprobante de venta no encontrado.', 404);
            }

            if ($comprobante->estado?->value === EstadoComprobanteVenta::Anulado->value || $comprobante->estado === 'Anulado') {
                DB::rollBack();

                return ApiResponse::error('No se pueden registrar pagos en un comprobante anulado.');
            }

            $esParaDetraccion = ! empty($payload['es_para_detraccion']) && (bool) $payload['es_para_detraccion'];
            $monto = (float) ($payload['monto_pagado'] ?? 0);
            if ($monto <= 0) {
                DB::rollBack();

                return ApiResponse::error('El monto a pagar debe ser mayor a cero.');
            }

            // Validar límite pendiente
            $montoNeto = (float) $comprobante->monto_neto;
            $avanceNeto = (float) $comprobante->avance_pago_neto;
            $montoDetraccionSoles = (float) $comprobante->monto_detraccion_soles;
            $avanceDetraccion = (float) $comprobante->avance_pago_detraccion;

            if ($esParaDetraccion) {
                $pendiente = max(round($montoDetraccionSoles - $avanceDetraccion, 2), 0.0);
                if ($monto > ($pendiente + 0.05)) {
                    DB::rollBack();

                    return ApiResponse::error("El monto ingresado (S/ {$monto}) supera el saldo pendiente de detracción (S/ {$pendiente}).");
                }
            } else {
                $pendiente = max(round($montoNeto - $avanceNeto, 2), 0.0);
                if ($monto > ($pendiente + 0.05)) {
                    DB::rollBack();

                    return ApiResponse::error("El monto ingresado ($ {$monto}) supera el saldo pendiente neto ($ {$pendiente}).");
                }
            }

            $medioPago = MedioPagoComprobanteVenta::tryFrom((string) ($payload['medio_pago'] ?? ''))
                ?? MedioPagoComprobanteVenta::Transferencia;

            if ($medioPago !== MedioPagoComprobanteVenta::Efectivo && empty($payload['numero_operacion'])) {
                DB::rollBack();

                return ApiResponse::error('El número de operación es obligatorio para transferencias y depósitos.');
            }

            $evidenciasGuardadas = ! empty($archivosEvidencia)
                ? ArchivoHelper::guardarArchivos('pagos_comprobante_venta', $archivosEvidencia)
                : [];

            $campos = [
                'id_comprobante_venta' => $idComprobante,
                'id_cuenta_bancaria_planta' => ! empty($payload['id_cuenta_bancaria_planta']) ? (int) $payload['id_cuenta_bancaria_planta'] : null,
                'id_cuenta_bancaria_empresa' => ! empty($payload['id_cuenta_bancaria_empresa']) ? (int) $payload['id_cuenta_bancaria_empresa'] : null,
                'id_empleado_registro' => (int) ($payload['id_empleado_registro'] ?? auth()->id() ?? 1),
                'id_empleado_anulacion' => null,
                'es_para_detraccion' => $esParaDetraccion ? 1 : 0,
                'medio_pago' => $medioPago->value,
                'monto_pagado' => $monto,
                'fecha_hora_pago' => (string) ($payload['fecha_hora_pago'] ?? now()->toDateTimeString()),
                'numero_operacion' => ! empty($payload['numero_operacion']) ? trim((string) $payload['numero_operacion']) : null,
                'observacion' => ! empty($payload['observacion']) ? trim((string) $payload['observacion']) : null,
                'evidencias' => json_encode($evidenciasGuardadas),
            ];

            ContabilidadVentaData::registrar_pago($campos);

            DB::commit();

            $comprobanteActualizado = ContabilidadVentaData::get_comprobante_by_id($idComprobante);

            return ApiResponse::success($comprobanteActualizado, 'Pago registrado exitosamente.');
        } catch (Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al registrar pago: '.$e->getMessage());
        }
    }

    /**
     * Anular un pago individual.
     *
     * @param  array<string,mixed>  $payload
     * @param  UploadedFile[]  $archivosEvidencia
     */
    public static function anular_pago(int $idPago, array $payload, array $archivosEvidencia = []): array
    {
        DB::beginTransaction();
        try {
            $motivo = trim((string) ($payload['motivo'] ?? ''));
            if (empty($motivo)) {
                DB::rollBack();

                return ApiResponse::error('El motivo de anulación es obligatorio.');
            }

            $idEmpleadoAnulacion = (int) ($payload['id_empleado_anulacion'] ?? auth()->id() ?? 1);
            $evidenciasGuardadas = ! empty($archivosEvidencia)
                ? ArchivoHelper::guardarArchivos('pagos_comprobante_venta/anulaciones', $archivosEvidencia)
                : null;

            $ok = ContabilidadVentaData::anular_pago($idPago, $idEmpleadoAnulacion, $motivo, $evidenciasGuardadas);
            if (! $ok) {
                DB::rollBack();

                return ApiResponse::error('El pago no existe o ya ha sido anulado.');
            }

            DB::commit();

            return ApiResponse::success(null, 'Pago anulado exitosamente.');
        } catch (Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al anular pago: '.$e->getMessage());
        }
    }
}
