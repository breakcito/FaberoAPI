<?php

namespace App\Modules\VisitaVehiculo\Services;

use App\Modules\VisitaVehiculo\Data\VisitaVehiculoData;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\UploadedFile;

class VisitaVehiculoService
{
    /**
     * Listar vehículos acompañantes de una visita.
     */
    public static function get_visitas_vehiculo(int $idRecepcionVisita): array
    {
        $data = VisitaVehiculoData::get_visitas_vehiculo($idRecepcionVisita);

        return ApiResponse::success($data, 'Vehículos acompañantes obtenidos correctamente');
    }

    /**
     * Crear un vehículo visitante. Genera automáticamente los N detalles de visita
     * (uno marcado como conductor, los demás como acompañantes).
     *
     * @param  array  $visitantes  Datos por visitante: nombre, apellido, dni, telefono, url_foto_documento (archivos).
     * @param  array  $archivosVehiculo  Archivos de fotos del vehículo.
     */
    public static function crear_visita_vehiculo(
        int $idRecepcionVisita,
        string $placa,
        int $cantidadPersonas,
        array $visitantes = [],
        array $archivosVehiculo = []
    ): array {
        $urlFoto = null;
        if (! empty($archivosVehiculo)) {
            $guardados = ArchivoHelper::guardarArchivos('visitas-vehiculos', $archivosVehiculo);
            if (! empty($guardados)) {
                $urls = array_map(fn ($f) => $f['url'], $guardados);
                $urlFoto = json_encode($urls);
            }
        }

        $id = VisitaVehiculoData::crear_visita_vehiculo(
            ['id_recepcion_visita' => $idRecepcionVisita, 'placa' => $placa, 'url_foto' => $urlFoto],
            $cantidadPersonas,
            $visitantes
        );

        $nuevo = VisitaVehiculoData::get_visita_vehiculo_by_id($id);

        return ApiResponse::success($nuevo, 'Vehículo acompañante registrado correctamente');
    }

    /**
     * Eliminar un vehículo visitante.
     */
    public static function eliminar_visita_vehiculo(int $id): array
    {
        VisitaVehiculoData::eliminar_visita_vehiculo($id);

        return ApiResponse::success(null, 'Vehículo acompañante eliminado correctamente');
    }
}
