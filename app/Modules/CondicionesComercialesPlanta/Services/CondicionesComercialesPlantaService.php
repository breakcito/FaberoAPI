<?php

namespace App\Modules\CondicionesComercialesPlanta\Services;

use App\Models\CondicionComercialPlanta;
use App\Models\PlantaDestino;
use App\Modules\CondicionesComercialesPlanta\Data\CondicionesComercialesPlantaData;
use App\Shared\Enums\_Generic\ElementoQuimicoValorizacion;
use App\Shared\Enums\_Generic\EstadoBase;
use App\Shared\Responses\ApiResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CondicionesComercialesPlantaService
{
    /**
     * Obtener el listado de condiciones comerciales de una planta destino.
     */
    public static function get_condiciones_por_planta(int $idPlanta, ?string $estado = null): array
    {
        $planta = PlantaDestino::find($idPlanta);
        if (! $planta) {
            return ApiResponse::error('La planta destino especificada no existe.');
        }

        $data = CondicionesComercialesPlantaData::get_condiciones_por_planta($idPlanta, $estado);

        return ApiResponse::success($data, 'Condiciones comerciales de planta obtenidas correctamente.');
    }

    /**
     * Crear una nueva condición comercial para una planta destino.
     */
    public static function crear_condicion(
        int $idPlanta,
        string $elementoQuimico,
        ?float $leyInicio,
        ?float $leyFinal,
        ?float $maquila,
        ?float $recuperacion,
        ?float $consumo,
        ?float $riesgoComercial
    ): array {
        $planta = PlantaDestino::find($idPlanta);
        if (! $planta) {
            return ApiResponse::error('La planta destino especificada no existe.');
        }

        $elementoEnum = ElementoQuimicoValorizacion::tryFrom($elementoQuimico);
        if (! $elementoEnum) {
            return ApiResponse::error('El elemento químico especificado no es válido.');
        }

        if ($leyInicio !== null && $leyFinal !== null && $leyInicio > $leyFinal) {
            return ApiResponse::error('La ley de inicio no puede ser mayor que la ley de fin.');
        }

        DB::beginTransaction();
        try {
            $condicion = CondicionComercialPlanta::create([
                'id_planta' => $idPlanta,
                'elemento_quimico' => $elementoEnum->value,
                'ley_inicio' => $leyInicio,
                'ley_fin' => $leyFinal,
                'maquila' => $maquila,
                'recuperacion' => $recuperacion,
                'consumo' => $consumo,
                'riesgo_comercial' => $riesgoComercial,
                'estado' => EstadoBase::Activo->value,
                'created_at' => Carbon::now(),
            ]);

            DB::commit();

            $data = CondicionesComercialesPlantaData::get_condicion_por_id($condicion->id);

            return ApiResponse::success($data, 'Condición comercial de planta registrada correctamente.');
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al registrar la condición comercial de planta: '.$e->getMessage());
        }
    }

    /**
     * Editar una condición comercial de planta existente.
     */
    public static function editar_condicion(
        int $id,
        string $elementoQuimico,
        ?float $leyInicio,
        ?float $leyFinal,
        ?float $maquila,
        ?float $recuperacion,
        ?float $consumo,
        ?float $riesgoComercial
    ): array {
        $condicion = CondicionComercialPlanta::find($id);
        if (! $condicion) {
            return ApiResponse::error('La condición comercial de planta no existe.');
        }

        $elementoEnum = ElementoQuimicoValorizacion::tryFrom($elementoQuimico);
        if (! $elementoEnum) {
            return ApiResponse::error('El elemento químico especificado no es válido.');
        }

        if ($leyInicio !== null && $leyFinal !== null && $leyInicio > $leyFinal) {
            return ApiResponse::error('La ley de inicio no puede ser mayor que la ley de fin.');
        }

        DB::beginTransaction();
        try {
            $condicion->elemento_quimico = $elementoEnum->value;
            $condicion->ley_inicio = $leyInicio;
            $condicion->ley_fin = $leyFinal;
            $condicion->maquila = $maquila;
            $condicion->recuperacion = $recuperacion;
            $condicion->consumo = $consumo;
            $condicion->riesgo_comercial = $riesgoComercial;
            $condicion->save();

            DB::commit();

            $data = CondicionesComercialesPlantaData::get_condicion_por_id($id);

            return ApiResponse::success($data, 'Condición comercial de planta actualizada correctamente.');
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al actualizar la condición comercial de planta: '.$e->getMessage());
        }
    }

    /**
     * Cambiar el estado (Activo / Inactivo) de una condición comercial de planta.
     */
    public static function cambiar_estado_condicion(int $id, string $nuevoEstado): array
    {
        $condicion = CondicionComercialPlanta::find($id);
        if (! $condicion) {
            return ApiResponse::error('La condición comercial de planta no existe.');
        }

        $estadoEnum = EstadoBase::tryFrom($nuevoEstado);
        if (! $estadoEnum) {
            return ApiResponse::error('El estado especificado no es válido.');
        }

        DB::beginTransaction();
        try {
            $condicion->estado = $estadoEnum->value;
            $condicion->save();

            DB::commit();

            $data = CondicionesComercialesPlantaData::get_condicion_por_id($id);

            return ApiResponse::success($data, 'Estado de la condición comercial de planta actualizado correctamente.');
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al cambiar el estado: '.$e->getMessage());
        }
    }
}
