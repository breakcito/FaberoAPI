<?php

namespace App\Modules\RecepcionUnidades\Services;

use App\Events\UnidadesParaPesarActualizadas;
use App\Modules\RecepcionUnidades\Data\RecepcionUnidadesData;
use App\Shared\Enums\_Generic\OrigenUnidadParaPesar;
use Illuminate\Support\Facades\Log;

/**
 * Difunde por WebSocket que hay una unidad disponible para pesar.
 *
 * La balanza (módulo de recepción de mineral) escucha este aviso y refresca
 * sola la lista de unidades por weighar, sin que el operador recargue.
 *
 * Vive en el módulo de Recepción de Unidades porque es el proceso que
 * habilita las unidades: allí se registran las recepciones y se confirman
 * las programaciones (de recepción y de despacho).
 */
class UnidadesParaPesarNotifier
{
    /**
     * Avisar que la recepción de unidad indicada ya está disponible para pesar.
     *
     * El aviso es BEST-EFFORT: si el servidor de Reverb no está levantado (o
     * no es alcanzable) la operación de negocio NO debe fallar. El error se
     * registra en el log y se sigue.
     */
    public static function notificar(int $idRecepcionUnidad): void
    {
        try {
            $recepcion = RecepcionUnidadesData::get_recepcion_by_id($idRecepcionUnidad);

            if (! $recepcion) {
                return;
            }

            broadcast(UnidadesParaPesarActualizadas::desdeRecepcion(
                self::determinarOrigen($recepcion),
                $recepcion,
            ));
        } catch (\Throwable $e) {
            Log::warning(
                'No se pudo difundir el aviso de unidad para pesar (recepción {id}): {msg}',
                ['id' => $idRecepcionUnidad, 'msg' => $e->getMessage()]
            );
        }
    }

    /**
     * El origen se deduce de la propia unidad, no del módulo que la habilitó:
     *  - sin programación        -> ingreso directo de una recepción,
     *  - con distribución        -> programación de despacho,
     *  - con programación y sin distribución -> programación de recepción.
     *
     * @param  array<string, mixed>  $recepcion
     */
    private static function determinarOrigen(array $recepcion): OrigenUnidadParaPesar
    {
        $esProgramacion = (int) ($recepcion['es_programacion'] ?? 0);

        if ($esProgramacion === 0) {
            return OrigenUnidadParaPesar::Recepcion;
        }

        $idDistribucion = $recepcion['id_distribucion'] ?? null;

        return ($idDistribucion !== null && $idDistribucion !== '')
            ? OrigenUnidadParaPesar::ProgramacionDespacho
            : OrigenUnidadParaPesar::ProgramacionRecepcion;
    }
}
