<?php

namespace App\Modules\AnticiposProveedor\Services;

use App\Models\AnticipoProveedor;
use App\Models\Proveedor;
use App\Modules\AnticiposProveedor\Data\AnticiposProveedorData;
use App\Shared\Enums\_Generic\EstadoAnticipoProveedor;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Responses\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
     * Gestionar las evidencias de un anticipo de proveedor: agregar archivos nuevos
     * y/o eliminar los que ya estaban adjuntos.
     *
     * @param  array<\Illuminate\Http\UploadedFile>  $archivosNuevos
     * @param  array<int, string>  $pathsEliminar  path_relativo de las evidencias a quitar
     */
    public static function gestionar_evidencias(int $id, array $archivosNuevos, array $pathsEliminar, int $idEmpleado, ?string $motivo = null): array
    {
        $anticipo = AnticipoProveedor::find($id);
        if (! $anticipo) {
            return ApiResponse::error('No se encontró el anticipo.', 404);
        }

        $evidenciasActuales = $anticipo->evidencias ?? [];
        if (! is_array($evidenciasActuales)) {
            $evidenciasActuales = json_decode((string) $evidenciasActuales, true) ?? [];
        }

        $pathsEliminar = array_values(array_unique(array_filter(
            array_map(static fn ($path): string => is_string($path) ? trim($path) : '', $pathsEliminar),
            static fn (string $path): bool => $path !== ''
        )));

        $nuevosGuardados = [];
        if (! empty($archivosNuevos)) {
            $nuevosGuardados = ArchivoHelper::guardarArchivos('anticipos', $archivosNuevos);
        }

        DB::beginTransaction();
        try {
            $eliminados = [];
            $restantes = [];

            foreach ($evidenciasActuales as $evidencia) {
                $pathRelativo = is_array($evidencia) ? (string) ($evidencia['path_relativo'] ?? '') : '';

                if ($pathRelativo !== '' && in_array($pathRelativo, $pathsEliminar, true)) {
                    try {
                        Storage::disk('public')->delete($pathRelativo);
                    } catch (\Throwable) {
                        // El archivo puede haber sido borrado antes: se ignora y se quita del listado.
                    }
                    $eliminados[] = $evidencia;

                    continue;
                }

                $restantes[] = $evidencia;
            }

            $anticipo->evidencias = array_merge($restantes, $nuevosGuardados);

            $cambios = [];
            foreach ($eliminados as $evidencia) {
                $cambios[] = [
                    'campo_bd' => 'evidencias',
                    'campo' => 'Evidencia eliminada',
                    'valor_anterior' => (string) ($evidencia['nombre_original'] ?? $evidencia['path_relativo'] ?? '—'),
                    'valor_nuevo' => '—',
                ];
            }
            foreach ($nuevosGuardados as $evidencia) {
                $cambios[] = [
                    'campo_bd' => 'evidencias',
                    'campo' => 'Evidencia agregada',
                    'valor_anterior' => '—',
                    'valor_nuevo' => (string) ($evidencia['nombre_original'] ?? $evidencia['path_relativo'] ?? '—'),
                ];
            }

            if (! empty($cambios)) {
                $logActual = $anticipo->log_cambios ?? [];
                if (! is_array($logActual)) {
                    $logActual = json_decode((string) $logActual, true) ?? [];
                }

                $logActual[] = [
                    'id_empleado' => $idEmpleado,
                    'fecha_hora' => now()->toDateTimeString(),
                    'update_at' => now()->toDateTimeString(),
                    'accion' => 'Gestión de Evidencias',
                    'motivo' => $motivo,
                    'cambios' => $cambios,
                ];

                $anticipo->log_cambios = $logActual;
            }

            $anticipo->save();

            DB::commit();

            $anticipoDetalle = AnticiposProveedorData::get_anticipo_by_id($id);

            return ApiResponse::success($anticipoDetalle, 'Evidencias actualizadas correctamente.');
        } catch (\Exception $e) {
            DB::rollBack();

            foreach ($nuevosGuardados as $evidencia) {
                $pathRelativo = (string) ($evidencia['path_relativo'] ?? '');
                if ($pathRelativo === '') {
                    continue;
                }
                try {
                    Storage::disk('public')->delete($pathRelativo);
                } catch (\Throwable) {
                    // El archivo huérfano ya no está en el disco: nada que limpiar.
                }
            }

            return ApiResponse::error('Error al actualizar las evidencias del anticipo: '.$e->getMessage());
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
