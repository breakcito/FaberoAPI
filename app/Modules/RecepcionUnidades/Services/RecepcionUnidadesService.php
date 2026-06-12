<?php

namespace App\Modules\RecepcionUnidades\Services;

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
     * Guardar archivos de evidencias y crear el registro de recepción.
     */
    public static function crear_recepcion(array $data, array $archivos): array
    {
        // Guardar los archivos de evidencias físicas en storage/app/public/recepciones
        $evidenciasGuardadas = [];
        if (!empty($archivos)) {
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
        if (!$recepcion) {
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
}
