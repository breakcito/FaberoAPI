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
            $anticipo = AnticipoProveedor::create([
                'id_proveedor_minero' => $data['id_proveedor_minero'],
                'id_empleado_registro' => $data['id_empleado_registro'],
                'serie_factura' => $data['serie_factura'] ?? null,
                'numero_factura' => $data['numero_factura'] ?? null,
                'saldo_inicial' => (float) $data['saldo_inicial'],
                'saldo_actual' => (float) $data['saldo_inicial'],
                'evidencias' => $evidenciasGuardadas,
                'log_cambios' => [],
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
                'motivo' => $motivo,
                'update_at' => now()->toDateTimeString(),
                'cambios' => [
                    [
                        'campo_bd' => 'estado',
                        'campo' => 'Estado',
                        'valor_anterior' => $oldEstado,
                        'valor_nuevo' => EstadoAnticipoProveedor::Anulado->value,
                    ],
                ],
            ];

            array_unshift($logActual, $nuevoLog);
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
}
