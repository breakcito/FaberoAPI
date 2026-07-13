<?php

namespace App\Modules\GestionLeyes\Services;

use App\Models\GrupoAnalisis;
use App\Models\Analito;
use App\Modules\GestionLeyes\Data\GestionLeyesData;
use App\Shared\Responses\ApiResponse;
use Illuminate\Support\Facades\DB;

class GestionLeyesService
{
    public static function get_grupos(): array
    {
        $data = GestionLeyesData::get_grupos();
        return ApiResponse::success($data, 'Grupos de análisis obtenidos correctamente');
    }

    public static function crear_grupo(string $nombre, int $orden, bool $indicarOrigen, array $analitos): array
    {
        $existe = GrupoAnalisis::where('nombre', $nombre)->exists();
        if ($existe) {
            return ApiResponse::error('Ya existe un grupo de análisis con ese nombre');
        }

        DB::beginTransaction();
        try {
            $id = GestionLeyesData::crear_grupo($nombre, $orden, $indicarOrigen);

            foreach ($analitos as $a) {
                GestionLeyesData::agregar_detalle_grupo(
                    $id,
                    $a['id_analito'],
                    $a['para_valorizacion_oro'] ?? false,
                    $a['para_valorizacion_plata'] ?? false,
                    $a['para_valorizacion_humedad'] ?? false,
                    $a['para_valorizacion_recuperacion'] ?? false
                );
            }

            DB::commit();
            
            $nuevoGrupo = GestionLeyesData::get_grupo_by_id($id);
            return ApiResponse::success($nuevoGrupo, 'Grupo de análisis creado correctamente');
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponse::error('Error al crear el grupo de análisis: ' . $e->getMessage());
        }
    }

    public static function editar_grupo(int $id, string $nombre, int $orden, bool $indicarOrigen, array $analitos): array
    {
        $existe = GrupoAnalisis::where('nombre', $nombre)->where('id', '!=', $id)->exists();
        if ($existe) {
            return ApiResponse::error('Ya existe otro grupo de análisis con ese nombre');
        }

        DB::beginTransaction();
        try {
            GestionLeyesData::editar_grupo($id, $nombre, $orden, $indicarOrigen);
            GestionLeyesData::eliminar_detalles_grupo($id);

            foreach ($analitos as $a) {
                GestionLeyesData::agregar_detalle_grupo(
                    $id,
                    $a['id_analito'],
                    $a['para_valorizacion_oro'] ?? false,
                    $a['para_valorizacion_plata'] ?? false,
                    $a['para_valorizacion_humedad'] ?? false,
                    $a['para_valorizacion_recuperacion'] ?? false
                );
            }

            DB::commit();

            $updated = GestionLeyesData::get_grupo_by_id($id);
            return ApiResponse::success($updated, 'Grupo de análisis editado correctamente');
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponse::error('Error al editar el grupo de análisis: ' . $e->getMessage());
        }
    }

    public static function cambiar_estado_grupo(int $id, string $estado): array
    {
        GestionLeyesData::cambiar_estado_grupo($id, $estado);
        $updated = GestionLeyesData::get_grupo_by_id($id);
        return ApiResponse::success($updated, 'Estado del grupo de análisis cambiado correctamente');
    }

    // Analitos business logic
    public static function get_analitos(): array
    {
        $data = GestionLeyesData::get_analitos();
        return ApiResponse::success($data, 'Analitos obtenidos correctamente');
    }

    public static function crear_analito(string $nombre, bool $esDesplegable): array
    {
        $existe = Analito::where('nombre', $nombre)->exists();
        if ($existe) {
            return ApiResponse::error('Ya existe un analito con ese nombre');
        }

        $id = GestionLeyesData::crear_analito($nombre, $esDesplegable);
        $nuevoAnalito = GestionLeyesData::get_analito_by_id($id);

        return ApiResponse::success($nuevoAnalito, 'Analito creado correctamente');
    }

    public static function cambiar_estado_analito(int $id, string $estado): array
    {
        GestionLeyesData::cambiar_estado_analito($id, $estado);
        $updated = GestionLeyesData::get_analito_by_id($id);
        return ApiResponse::success($updated, 'Estado del analito cambiado correctamente');
    }

    public static function editar_analito(int $id, string $nombre, bool $esDesplegable): array
    {
        $existe = Analito::where('nombre', $nombre)->where('id', '!=', $id)->exists();
        if ($existe) {
            return ApiResponse::error('Ya existe un analito con ese nombre');
        }

        GestionLeyesData::editar_analito($id, $nombre, $esDesplegable);
        $updated = GestionLeyesData::get_analito_by_id($id);

        return ApiResponse::success($updated, 'Analito editado correctamente');
    }
}
