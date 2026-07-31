<?php

namespace App\Modules\AnticiposProveedor\Services;

use App\Models\AnticipoProveedor;
use App\Models\Proveedor;
use App\Modules\AnticiposProveedor\Data\AnticiposProveedorData;
use App\Shared\Enums\_Generic\EstadoAnticipoProveedor;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Responses\ApiResponse;
use Illuminate\Support\Facades\DB;

class AnticiposProveedorService
{
    /**
     * Obtener listado de anticipos de proveedor.
     *
     * @param  array{id_proveedor_minero?: int|null, estado?: string|null, fecha_inicio?: string|null, fecha_fin?: string|null}  $filters
     */
    public static function get_anticipos(array $filters = []): array
    {
        $data = AnticiposProveedorData::get_anticipos($filters);

        return ApiResponse::success($data, 'Anticipos obtenidos correctamente.');
    }

    /**
     * Crear un nuevo anticipo de proveedor.
     *
     * @param  array{id_proveedor_minero: int, id_empleado_registro: int, serie_factura?: string|null, numero_factura?: string|null, saldo_inicial: float}  $data
     * @param  array<\Illuminate\Http\UploadedFile>  $archivos
     */
    public static function crear_anticipo(array $data, array $archivos = []): array
    {
        $proveedor = Proveedor::find($data['id_proveedor_minero']);
        if (! $proveedor) {
            return ApiResponse::error('El proveedor minero no existe.');
        }

        $evidenciasGuardadas = [];
        if (! empty($archivos)) {
            $evidenciasGuardadas = ArchivoHelper::guardarArchivos('anticipos', $archivos);
        }

        DB::beginTransaction();
        try {
            $saldoInicialFmt = '$ '.number_format((float) $data['saldo_inicial'], 2);
            $logInicial = [
                [
                    'id_empleado' => (int) $data['id_empleado_registro'],
                    'fecha_hora' => now()->toDateTimeString(),
                    'update_at' => now()->toIso8601String(),
                    'accion' => 'Registro de Anticipo',
                    'motivo' => 'Registro inicial de anticipo a proveedor',
                    'cambios' => [
                        [
                            'campo_bd' => 'saldo_inicial',
                            'campo' => 'Monto Inicial',
                            'valor_anterior' => '—',
                            'valor_nuevo' => $saldoInicialFmt,
                        ],
                        [
                            'campo_bd' => 'saldo_actual',
                            'campo' => 'Saldo Actual',
                            'valor_anterior' => '—',
                            'valor_nuevo' => $saldoInicialFmt,
                        ],
                        [
                            'campo_bd' => 'estado',
                            'campo' => 'Estado',
                            'valor_anterior' => '—',
                            'valor_nuevo' => EstadoAnticipoProveedor::ConSaldo->value,
                        ],
                    ],
                ],
            ];

            $anticipo = AnticipoProveedor::create([
                'id_proveedor_minero' => $data['id_proveedor_minero'],
                'id_empleado_registro' => $data['id_empleado_registro'],
                'serie_factura' => $data['serie_factura'] ?? null,
                'numero_factura' => $data['numero_factura'] ?? null,
                'saldo_inicial' => (float) $data['saldo_inicial'],
                'saldo_actual' => (float) $data['saldo_inicial'],
                'evidencias' => $evidenciasGuardadas,
                'log_cambios' => $logInicial,
                'estado' => EstadoAnticipoProveedor::ConSaldo->value,
                'created_at' => now()->toDateTimeString(),
            ]);

            DB::commit();

            $anticipoDetalle = AnticiposProveedorData::get_anticipo_by_id($anticipo->id);

            return ApiResponse::success($anticipoDetalle, 'Anticipo registrado correctamente.');
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al registrar el anticipo: '.$e->getMessage());
        }
    }

    /**
     * Anular un anticipo existente.
     */
    public static function anular_anticipo(int $id, string $motivo, int $idEmpleado): array
    {
        $anticipo = AnticipoProveedor::find($id);
        if (! $anticipo) {
            return ApiResponse::error('No se encontró el anticipo.');
        }

        if ($anticipo->estado === EstadoAnticipoProveedor::Anulado->value) {
            return ApiResponse::error('El anticipo ya se encuentra anulado.');
        }

        $oldEstado = $anticipo->estado;

        DB::beginTransaction();
        try {
            $anticipo->estado = EstadoAnticipoProveedor::Anulado->value;

            $logActual = $anticipo->log_cambios ?? [];
            if (! is_array($logActual)) {
                $logActual = json_decode((string) $logActual, true) ?? [];
            }

            $nuevoLog = [
                'id_empleado' => $idEmpleado,
                'fecha_hora' => now()->toDateTimeString(),
                'update_at' => now()->toDateTimeString(),
                'accion' => 'Anulación de Anticipo',
                'motivo' => $motivo,
                'cambios' => [
                    [
                        'campo_bd' => 'estado',
                        'campo' => 'Estado',
                        'valor_anterior' => $oldEstado,
                        'valor_nuevo' => EstadoAnticipoProveedor::Anulado->value,
                    ],
                ],
            ];

            $logActual[] = $nuevoLog;
            $anticipo->log_cambios = $logActual;
            $anticipo->save();

            DB::commit();

            $anticipoDetalle = AnticiposProveedorData::get_anticipo_by_id($id);

            return ApiResponse::success($anticipoDetalle, 'Anticipo anulado correctamente.');
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al anular el anticipo: '.$e->getMessage());
        }
    }

    /**
     * Obtener transacciones asociadas a un anticipo.
     */
    public static function get_transacciones(int $id): array
    {
        $data = AnticiposProveedorData::get_transacciones_by_anticipo($id);

        return ApiResponse::success($data, 'Transacciones obtenidas correctamente.');
    }

    /**
     * Obtener historial de cambios unificado (cabecera + transacciones).
     */
    public static function get_historial_combinado(int $id): array
    {
        $data = AnticiposProveedorData::get_historial_cambios_combinado($id);

        return ApiResponse::success($data, 'Historial de cambios obtenido correctamente.');
    }
}
