<?php

namespace App\Modules\AnticiposPlanta\Services;

use App\Models\AnticipoPlanta;
use App\Models\PlantaDestino;
use App\Modules\AnticiposPlanta\Data\AnticiposPlantaData;
use App\Shared\Enums\_Generic\EstadoAnticipoProveedor;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Responses\ApiResponse;
use Illuminate\Support\Facades\DB;

class AnticiposPlantaService
{
    /**
     * Obtener listado de anticipos de planta.
     *
     * @param  array{id_planta?: int|null, estado?: string|null, fecha_inicio?: string|null, fecha_fin?: string|null}  $filters
     */
    public static function get_anticipos(array $filters = []): array
    {
        $data = AnticiposPlantaData::get_anticipos($filters);

        return ApiResponse::success($data, 'Anticipos de planta obtenidos correctamente.');
    }

    /**
     * Crear un nuevo anticipo de planta.
     *
     * @param  array{id_planta: int, id_empleado_registro: int, codigo_comprobante?: string|null, saldo_inicial: float}  $data
     * @param  array<\Illuminate\Http\UploadedFile>  $archivos
     */
    public static function crear_anticipo(array $data, array $archivos = []): array
    {
        $planta = PlantaDestino::find($data['id_planta']);
        if (! $planta) {
            return ApiResponse::error('La planta destino no existe.');
        }

        $evidenciasGuardadas = [];
        if (! empty($archivos)) {
            $evidenciasGuardadas = ArchivoHelper::guardarArchivos('anticipos-planta', $archivos);
        }

        DB::beginTransaction();
        try {
            $anticipo = AnticipoPlanta::create([
                'id_planta' => $data['id_planta'],
                'id_empleado_registro' => $data['id_empleado_registro'],
                'codigo_comprobante' => $data['codigo_comprobante'] ?? null,
                'saldo_inicial' => (float) $data['saldo_inicial'],
                'saldo_actual' => (float) $data['saldo_inicial'],
                'evidencias' => $evidenciasGuardadas,
                'log_cambios' => [],
                'estado' => EstadoAnticipoProveedor::ConSaldo->value,
                'created_at' => now()->toDateTimeString(),
            ]);

            DB::commit();

            $anticipoDetalle = AnticiposPlantaData::get_anticipo_by_id($anticipo->id);

            return ApiResponse::success($anticipoDetalle, 'Anticipo de planta registrado correctamente.');
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al registrar el anticipo de planta: '.$e->getMessage());
        }
    }

    /**
     * Anular un anticipo de planta existente.
     */
    public static function anular_anticipo(int $id, string $motivo, int $idEmpleado): array
    {
        $anticipo = AnticipoPlanta::find($id);
        if (! $anticipo) {
            return ApiResponse::error('No se encontró el anticipo de planta.');
        }

        if ($anticipo->estado === EstadoAnticipoProveedor::Anulado->value) {
            return ApiResponse::error('El anticipo de planta ya se encuentra anulado.');
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

            $anticipoDetalle = AnticiposPlantaData::get_anticipo_by_id($id);

            return ApiResponse::success($anticipoDetalle, 'Anticipo de planta anulado correctamente.');
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al anular el anticipo de planta: '.$e->getMessage());
        }
    }

    /**
     * Obtener un anticipo de planta específico por ID.
     */
    public static function get_anticipo_by_id(int $id): array
    {
        $data = AnticiposPlantaData::get_anticipo_by_id($id);
        if (! $data) {
            return ApiResponse::error('No se encontró el anticipo de planta.', 404);
        }

        return ApiResponse::success($data, 'Anticipo de planta obtenido correctamente.');
    }
}
