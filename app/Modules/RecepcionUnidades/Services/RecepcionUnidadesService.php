<?php

namespace App\Modules\RecepcionUnidades\Services;

use App\Models\RecepcionUnidad;
use App\Modules\RecepcionUnidades\Data\RecepcionUnidadesData;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Responses\ApiResponse;

class RecepcionUnidadesService
{
    /**
     * Obtener listado de recepciones filtradas.
     */
    public static function get_recepciones(array $filters): array
    {
        $data = RecepcionUnidadesData::get_recepciones($filters);

        return ApiResponse::success($data, 'Recepciones obtenidas correctamente');
    }

    /**
     * Obtener una recepción puntual (incluye sus lotes).
     */
    public static function get_recepcion(int $id): array
    {
        $recepcion = RecepcionUnidadesData::get_recepcion_by_id($id);
        if (! $recepcion) {
            return ApiResponse::error('No se encontró el registro de recepción.', 404);
        }

        $recepcion['lotes'] = RecepcionUnidadesData::get_lotes($id);

        return ApiResponse::success($recepcion, 'Recepción obtenida correctamente');
    }

    /**
     * Guardar archivos de evidencias y crear el registro de recepción.
     */
    public static function crear_recepcion(array $data, array $archivos): array
    {
        // Guardar los archivos de evidencias físicas en storage/app/public/recepciones
        $evidenciasGuardadas = [];
        if (! empty($archivos)) {
            $evidenciasGuardadas = ArchivoHelper::guardarArchivos('recepciones', $archivos);
        }

        $data['evidencias'] = $evidenciasGuardadas;

        $id = RecepcionUnidadesData::crear_recepcion($data);
        $nuevaRecepcion = RecepcionUnidadesData::get_recepcion_by_id($id);

        return ApiResponse::success($nuevaRecepcion, 'Recepción de unidad registrada correctamente');
    }

    /**
     * Registrar la salida de una unidad.
     */
    public static function registrar_salida(int $id, string $estadoSalida, ?string $observacionSalida): array
    {
        $recepcion = \App\Models\RecepcionUnidad::find($id);
        if (! $recepcion) {
            return ApiResponse::error('No se encontró el registro de recepción.');
        }

        $recepcion->estado_salida = $estadoSalida;
        $recepcion->observacion_salida = $observacionSalida;
        $recepcion->fecha_hora_salida = now()->toDateTimeString();
        $recepcion->estado = 'Fuera de Planta';
        $recepcion->save();

        $updated = RecepcionUnidadesData::get_recepcion_by_id($id);

        return ApiResponse::success($updated, 'Salida de unidad registrada correctamente');
    }

    /**
     * Listar lotes de una recepción de unidad.
     */
    public static function get_lotes(int $id): array
    {
        $data = RecepcionUnidadesData::get_lotes($id);

        return ApiResponse::success($data, 'Lotes obtenidos correctamente');
    }

    /**
     * Generar un nuevo lote para la recepción de unidad indicada.
     */
    public static function crear_lote(int $id, int $idEmpleado): array
    {
        $recepcion = RecepcionUnidad::find($id);
        if (! $recepcion) {
            return ApiResponse::error('No se encontró el registro de recepción.', 404);
        }

        $lote = RecepcionUnidadesData::crear_lote($id, $idEmpleado);

        return ApiResponse::success($lote, 'Lote generado correctamente');
    }

    /**
     * Eliminar un lote.
     */
    public static function eliminar_lote(int $loteId): array
    {
        $deleted = RecepcionUnidadesData::eliminar_lote($loteId);
        if (! $deleted) {
            return ApiResponse::error('No se encontró el lote a eliminar.', 404);
        }

        return ApiResponse::success(null, 'Lote eliminado correctamente');
    }
}
