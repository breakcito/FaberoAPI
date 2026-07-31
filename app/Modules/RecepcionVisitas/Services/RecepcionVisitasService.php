<?php

namespace App\Modules\RecepcionVisitas\Services;

use App\Models\Visitante;
use App\Modules\RecepcionVisitas\Data\RecepcionVisitasData;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Responses\ApiResponse;
use Illuminate\Support\Facades\DB;

class RecepcionVisitasService
{
    /**
     * Obtener listado de recepciones de visitas.
     */
    public static function get_recepciones(array $filters): array
    {
        $data = RecepcionVisitasData::get_recepciones($filters);

        return ApiResponse::success($data, 'Recepciones de visitas obtenidas correctamente');
    }

    /**
     * Registrar una recepción de visita y sus detalles de visitantes.
     */
    public static function crear_recepcion(array $data, array $visitantes, array $archivos): array
    {
        $id = null;

        try {
            DB::beginTransaction();

            $id = RecepcionVisitasData::crear_recepcion($data);

            foreach ($visitantes as $index => $v) {
                // 1. Obtener o crear al visitante y actualizar sus datos
                $idVisitante = null;
                if (! empty($v['id_visitante'])) {
                    $idVisitante = (int) $v['id_visitante'];
                    Visitante::whereKey($idVisitante)->update([
                        'nombre' => $v['nombre'],
                        'apellido' => $v['apellido'],
                        'telefono' => $v['telefono'] ?? null,
                    ]);
                } else {
                    // Buscar por DNI por si acaso ya estuviera registrado
                    $existente = \App\Data\VisitanteData::buscar_por_dni($v['dni']);
                    if ($existente) {
                        $idVisitante = $existente['id_visitante'];
                        Visitante::whereKey($idVisitante)->update([
                            'nombre' => $v['nombre'],
                            'apellido' => $v['apellido'],
                            'telefono' => $v['telefono'] ?? null,
                        ]);
                    } else {
                        $idVisitante = \App\Data\VisitanteData::crear_visitante([
                            'nombre' => $v['nombre'],
                            'apellido' => $v['apellido'],
                            'dni' => $v['dni'],
                            'telefono' => $v['telefono'] ?? null,
                        ]);
                    }
                }

                // 2. Guardar la foto del documento si viene en los archivos
                $urlFoto = null;
                if (isset($archivos[$index])) {
                    $uploaded = ArchivoHelper::guardarArchivos('visitas', $archivos[$index]);
                    if (! empty($uploaded)) {
                        $urls = array_map(function ($file) {
                            return $file['url'];
                        }, $uploaded);
                        $urlFoto = json_encode($urls);
                    }
                }

                // 3. Crear el detalle de la visita
                \App\Models\RecepcionVisitaDetalle::create([
                    'id_recepcion_visita' => $id,
                    'id_visitante' => $idVisitante,
                    'url_foto_documento' => $urlFoto,
                    'estado' => 1, // 1 = En Planta
                ]);
            }

            DB::commit();

            $nuevaRecepcion = RecepcionVisitasData::get_recepcion_by_id($id);

            return ApiResponse::success($nuevaRecepcion, 'Recepción de visita registrada correctamente');

        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al registrar la recepción de visita: '.$e->getMessage());
        }
    }

    /**
     * Registrar la salida de una visita.
     */
    public static function registrar_salida(int $idDetalle, ?string $observacionSalida): array
    {
        $detalle = \App\Models\RecepcionVisitaDetalle::find($idDetalle);
        if (! $detalle) {
            return ApiResponse::error('No se encontró el detalle de la recepción de visita.');
        }

        $detalle->observacion_salida = $observacionSalida;
        $detalle->fecha_hora_salida = now()->toDateTimeString();
        $detalle->estado = 2; // 2 = Fuera de Planta
        $detalle->save();

        $updated = RecepcionVisitasData::get_recepcion_by_id($detalle->id_recepcion_visita);

        return ApiResponse::success($updated, 'Salida de visita registrada correctamente');
    }
}
