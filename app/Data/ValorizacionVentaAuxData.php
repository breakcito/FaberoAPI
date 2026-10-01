<?php

namespace App\Data;

use App\Models\CondicionComercialPlanta;
use App\Shared\Enums\_Generic\ElementoQuimicoValorizacion;
use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Support\Facades\DB;

class ValorizacionVentaAuxData
{
    /**
     * Plantas destino activas que tienen al menos un despacho_detalle
     * potencialmente valorizable con leyes confirmadas y no valorizado.
     */
    public static function get_plantas_con_distribuciones(?int $idValorizacionEdicion = null): array
    {
        $sql = "
            SELECT DISTINCT
                pd.id,
                pd.ruc,
                pd.razon_social
            FROM planta_destino pd
            INNER JOIN despacho d ON d.id_planta_destino = pd.id
            INNER JOIN despacho_detalle dd ON dd.id_despacho = d.id
            WHERE pd.estado = 'Activo'
              AND d.es_anulado = 0
              AND (dd.ley_oro_final_confirmada = 1 OR dd.ley_plata_final_confirmada = 1)
              AND NOT (dd.esta_valorizado_oro = 1 AND dd.esta_valorizado_plata = 1)
            ORDER BY pd.razon_social ASC
        ";

        $rows = DB::select($sql);

        return array_map(static fn ($r) => [
            'id' => (int) $r->id,
            'ruc' => (string) ($r->ruc ?? ''),
            'razon_social' => (string) ($r->razon_social ?? ''),
        ], $rows);
    }

    /**
     * despacho_detalle disponibles para valorizar de una planta específica.
     *
     * Reglas de inclusión (todas deben cumplirse):
     *  - Pertenecer a un despacho cuya planta_destino = id_planta y no esté anulado.
     *  - Todas las distribuciones hijas deben haber llegado al cliente (fecha_llegada_cliente IS NOT NULL).
     *  - Todas las distribuciones hijas deben tener peso_neto_cliente > 0.
     *  - La suma de peso_neto_cliente de las distribuciones debe ser igual a peso_tomado de despacho_detalle.
     *  - Al menos una ley final confirmada (ley_oro_final_confirmada = 1 o ley_plata_final_confirmada = 1).
     *  - Excluye los items que ya están valorizados para AMBOS elementos (Oro+Plata).
     *    Si se pasa idValorizacionEdicion, se permiten los detalles ya usados por esa
     *    valorización (para que la edición los siga viendo disponibles).
     */
    public static function get_distribuciones_detalles_disponibles(int $idPlanta, ?int $idValorizacionEdicion = null): array
    {
        $sql = "
            SELECT
                dd.id AS id_despacho_detalle,
                dd.id_despacho,
                dd.codigo_preliminar,
                dd.peso_tomado,
                dd.ley_oro_final,
                dd.ley_plata_final,
                dd.ley_oro_final_confirmada,
                dd.ley_plata_final_confirmada,
                dd.esta_valorizado_oro,
                dd.esta_valorizado_plata,
                dsp.correlativo AS despacho_correlativo,
                pd.id AS id_planta,
                pd.razon_social AS planta_nombre,
                dd.id_lote_mineral,
                dd.id_blending,
                lm.correlativo AS lote_correlativo,
                b.correlativo AS blending_correlativo,
                (
                    SELECT GROUP_CONCAT(DISTINCT ddt.codigo_cliente ORDER BY ddt.id SEPARATOR '.')
                    FROM distribucion_detalle ddt
                    WHERE ddt.id_despacho_detalle = dd.id
                      AND ddt.codigo_cliente IS NOT NULL
                      AND TRIM(ddt.codigo_cliente) != ''
                ) AS codigos_cliente,
                (
                    SELECT COALESCE(SUM(ddt.peso_tomado), 0)
                    FROM distribucion_detalle ddt
                    WHERE ddt.id_despacho_detalle = dd.id
                ) AS peso_distribuido_total,
                (
                    SELECT AVG(ddt.ley_humedad_cliente)
                    FROM distribucion_detalle ddt
                    WHERE ddt.id_despacho_detalle = dd.id AND ddt.ley_humedad_cliente IS NOT NULL
                ) AS ley_humedad_cliente,
                (
                    SELECT COALESCE(SUM(ddt.peso_neto_cliente), 0)
                    FROM distribucion_detalle ddt
                    WHERE ddt.id_despacho_detalle = dd.id
                ) AS peso_neto_cliente_total,
                (
                    SELECT COUNT(*)
                    FROM distribucion_detalle ddt
                    WHERE ddt.id_despacho_detalle = dd.id
                ) AS total_distribuciones,
                (
                    SELECT COUNT(*)
                    FROM distribucion_detalle ddt
                    INNER JOIN distribucion di ON di.id = ddt.id_distribucion
                    WHERE ddt.id_despacho_detalle = dd.id
                      AND di.fecha_llegada_cliente IS NOT NULL
                      AND ddt.peso_neto_cliente > 0
                ) AS total_distribuciones_validas
            FROM despacho_detalle dd
            INNER JOIN despacho dsp ON dsp.id = dd.id_despacho
            INNER JOIN planta_destino pd ON pd.id = dsp.id_planta_destino
            LEFT JOIN lote_mineral lm ON lm.id = dd.id_lote_mineral
            LEFT JOIN blending b ON b.id = dd.id_blending
            WHERE dsp.id_planta_destino = :id_planta
              AND dsp.es_anulado = 0
              AND (dd.ley_oro_final_confirmada = 1 OR dd.ley_plata_final_confirmada = 1)
            ORDER BY dd.id DESC
        ";

        $rows = DB::select($sql, ['id_planta' => $idPlanta]);

        // Cargar condiciones comerciales activas de la planta (Oro y Plata)
        // para auto-asignar la condición cuyo rango de ley coincida con
        // la ley final confirmada en cada despacho_detalle.
        $condicionesPlanta = CondicionComercialPlanta::query()
            ->where('id_planta', $idPlanta)
            ->where('estado', EstadoBase::Activo->value)
            ->get()
            ->sortByDesc('ley_inicio')
            ->values();

        $result = [];
        foreach ($rows as $row) {
            $idDet = (int) $row->id_despacho_detalle;
            $valorizadoOro = (bool) ($row->esta_valorizado_oro ?? false);
            $valorizadoPlata = (bool) ($row->esta_valorizado_plata ?? false);

            $totalDist = (int) ($row->total_distribuciones ?? 0);
            $totalValidas = (int) ($row->total_distribuciones_validas ?? 0);
            $pesoTomado = (float) $row->peso_tomado;
            $pesoDistribuidoTotal = (float) ($row->peso_distribuido_total ?? 0);

            // Validaciones para poder valorizar:
            // 1. Debe tener distribuciones registradas
            // 2. Todas las distribuciones deben haber llegado al cliente con peso neto cliente > 0
            if ($totalDist === 0 || $totalDist !== $totalValidas) {
                continue;
            }

            // 3. El peso tomado debe haber sido distribuido en su totalidad (sin peso pendiente)
            if (abs($pesoDistribuidoTotal - $pesoTomado) > 0.01) {
                continue;
            }

            // Si está valorizado por AMBOS elementos, queda excluido salvo que esté
            // vinculado a la valorización que estamos editando.
            if ($valorizadoOro && $valorizadoPlata) {
                if ($idValorizacionEdicion === null) {
                    continue;
                }
                $yaUsado = DB::table('valorizacion_venta_detalle as vvd')
                    ->join('valorizacion_venta as vv', 'vv.id', '=', 'vvd.id_valorizacion_venta')
                    ->where('vvd.id_despacho_detalle', $idDet)
                    ->where('vv.id', $idValorizacionEdicion)
                    ->exists();
                if (! $yaUsado) {
                    continue;
                }
            }

            // Buscar condición comercial para Oro: rango [ley_inicio, ley_fin] que
            // contenga la ley_oro_final del detalle.
            $leyOroFinal = (float) ($row->ley_oro_final ?? 0);
            $condOro = null;
            if ($row->ley_oro_final_confirmada) {
                $condOro = $condicionesPlanta->first(function ($c) use ($leyOroFinal) {
                    if ($c->elemento_quimico !== ElementoQuimicoValorizacion::Oro) {
                        return false;
                    }
                    $inicio = (float) $c->ley_inicio;
                    $fin = (float) $c->ley_fin;

                    return $leyOroFinal >= $inicio && $leyOroFinal <= $fin;
                });
            }

            // Buscar condición comercial para Plata: rango [ley_inicio, ley_fin] que
            // contenga la ley_plata_final del detalle.
            $leyPlataFinal = (float) ($row->ley_plata_final ?? 0);
            $condPlata = null;
            if ($row->ley_plata_final_confirmada) {
                $condPlata = $condicionesPlanta->first(function ($c) use ($leyPlataFinal) {
                    if ($c->elemento_quimico !== ElementoQuimicoValorizacion::Plata) {
                        return false;
                    }
                    $inicio = (float) $c->ley_inicio;
                    $fin = (float) $c->ley_fin;

                    return $leyPlataFinal >= $inicio && $leyPlataFinal <= $fin;
                });
            }

            $result[] = [
                'id_despacho_detalle' => $idDet,
                'id_distribucion_detalle' => $idDet, // compatibilidad
                'codigo_preliminar' => $row->codigo_preliminar !== null ? (string) $row->codigo_preliminar : null,
                'peso_tomado' => $pesoTomado,
                'peso_neto_cliente' => $pesoTomado,
                'ley_humedad_cliente' => $row->ley_humedad_cliente !== null ? round((float) $row->ley_humedad_cliente, 3) : 0.0,
                'ley_oro_final' => $leyOroFinal,
                'ley_plata_final' => $leyPlataFinal,
                'ley_oro_final_confirmada' => (bool) $row->ley_oro_final_confirmada,
                'ley_plata_final_confirmada' => (bool) $row->ley_plata_final_confirmada,
                'esta_valorizado_oro' => $valorizadoOro,
                'esta_valorizado_plata' => $valorizadoPlata,
                'id_despacho' => (int) $row->id_despacho,
                'despacho_correlativo' => $row->despacho_correlativo !== null ? (string) $row->despacho_correlativo : null,
                'id_planta' => (int) $row->id_planta,
                'planta_nombre' => (string) ($row->planta_nombre ?? ''),
                'id_lote_mineral' => $row->id_lote_mineral !== null ? (int) $row->id_lote_mineral : null,
                'id_blending' => $row->id_blending !== null ? (int) $row->id_blending : null,
                'lote_correlativo' => $row->lote_correlativo !== null ? (string) $row->lote_correlativo : null,
                'blending_correlativo' => $row->blending_correlativo !== null ? (string) $row->blending_correlativo : null,
                'codigo_cliente' => $row->codigos_cliente !== null ? (string) $row->codigos_cliente : null,
                'codigos_cliente' => $row->codigos_cliente !== null ? (string) $row->codigos_cliente : null,
                'condicion_oro' => $condOro ? [
                    'id_condicion_comercial' => (int) $condOro->id,
                    'recuperacion' => (float) $condOro->recuperacion,
                    'maquila' => (float) $condOro->maquila,
                    'consumo' => (float) $condOro->consumo,
                ] : null,
                'condicion_plata' => $condPlata ? [
                    'id_condicion_comercial' => (int) $condPlata->id,
                    'recuperacion' => (float) $condPlata->recuperacion,
                    'maquila' => (float) $condPlata->maquila,
                    'consumo' => (float) $condPlata->consumo,
                ] : null,
            ];
        }

        return $result;
    }

    /**
     * Condiciones comerciales activas para una planta destino, indexadas por
     * elemento químico (Oro / Plata).
     */
    public static function get_condiciones_comerciales_planta(int $idPlanta): array
    {
        $rows = DB::table('condicion_comercial_planta as ccp')
            ->where('ccp.id_planta', $idPlanta)
            ->where('ccp.estado', 'Activo')
            ->select([
                'ccp.id',
                'ccp.id_planta',
                'ccp.elemento_quimico',
                'ccp.ley_inicio',
                'ccp.ley_fin',
                'ccp.maquila',
                'ccp.recuperacion',
                'ccp.consumo',
                'ccp.riesgo_comercial',
            ])
            ->orderBy('ccp.id', 'ASC')
            ->get();

        $porElemento = ['Oro' => [], 'Plata' => []];
        foreach ($rows as $r) {
            $porElemento[(string) $r->elemento_quimico][] = [
                'id' => (int) $r->id,
                'id_planta' => (int) $r->id_planta,
                'elemento_quimico' => (string) $r->elemento_quimico,
                'ley_inicio' => (float) $r->ley_inicio,
                'ley_fin' => (float) $r->ley_fin,
                'maquila' => (float) $r->maquila,
                'recuperacion' => (float) $r->recuperacion,
                'consumo' => (float) $r->consumo,
                'riesgo_comercial' => (float) $r->riesgo_comercial,
            ];
        }

        return $porElemento;
    }
}
