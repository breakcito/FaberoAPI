<?php

namespace App\Data;

use App\Models\CondicionComercialPlanta;
use App\Shared\Enums\_Generic\ElementoQuimicoValorizacion;
use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Support\Facades\DB;

class ValorizacionVentaAuxData
{
    /**
     * Plantas destino activas que tienen al menos una distribucion_detalle
     * potencialmente valorizable: distribucion en estado "Llegó al Cliente"
     * y los 4 campos del cliente con valores mayores a cero.
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
            INNER JOIN distribucion di ON di.id_despacho = d.id
            INNER JOIN distribucion_detalle ddt ON ddt.id_distribucion = di.id
            WHERE pd.estado = 'Activo'
              AND di.estado = 'Llegó al Cliente'
              AND ddt.peso_neto_cliente > 0
              AND ddt.ley_humedad_cliente > 0
              AND ddt.ley_oro_cliente > 0
              AND ddt.ley_plata_cliente > 0
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
     * distribucion_detalle disponibles para valorizar de una planta específica.
     *
     * Reglas de inclusión (todas deben cumplirse):
     *  - Pertenecer a un despacho cuya planta_destino = id_planta.
     *  - La distribucion padre debe estar en estado "Llegó al Cliente" (mineral ya entregado al cliente).
     *  - peso_neto_cliente > 0
     *  - ley_humedad_cliente > 0
     *  - ley_oro_cliente > 0
     *  - ley_plata_cliente > 0
     *  - Excluye las que ya están valorizadas para AMBOS elementos (Oro+Plata).
     *    Si se pasa idValorizacionEdicion, se permiten los detalles ya usados por esa
     *    valorización (para que la edición los siga viendo disponibles).
     */
    public static function get_distribuciones_detalles_disponibles(int $idPlanta, ?int $idValorizacionEdicion = null): array
    {
        $sql = "
            SELECT
                ddt.id AS id_distribucion_detalle,
                ddt.id_distribucion,
                ddt.id_despacho_detalle,
                ddt.numero_particion,
                ddt.peso_neto_cliente,
                ddt.ley_oro_cliente,
                ddt.ley_plata_cliente,
                ddt.ley_humedad_cliente,
                ddt.codigo_cliente,
                ddt.esta_valorizado_oro,
                ddt.esta_valorizado_plata,
                d.id_despacho,
                d.estado AS distribucion_estado,
                d.fecha_llegada_cliente,
                dsp.id AS id_despacho,
                dsp.correlativo AS despacho_correlativo,
                pd.id AS id_planta,
                pd.razon_social AS planta_nombre,
                dd.id_lote_mineral,
                dd.id_blending,
                lm.correlativo AS lote_correlativo,
                lm.ley_oro AS lote_ley_oro,
                lm.ley_plata AS lote_ley_plata,
                lm.ley_humedad AS lote_ley_humedad,
                b.correlativo AS blending_correlativo
            FROM distribucion_detalle ddt
            INNER JOIN distribucion d ON d.id = ddt.id_distribucion
            INNER JOIN despacho dsp ON dsp.id = d.id_despacho
            INNER JOIN despacho_detalle dd ON dd.id = ddt.id_despacho_detalle
            INNER JOIN planta_destino pd ON pd.id = dsp.id_planta_destino
            LEFT JOIN lote_mineral lm ON lm.id = dd.id_lote_mineral
            LEFT JOIN blending b ON b.id = dd.id_blending
            WHERE dsp.id_planta_destino = :id_planta
              AND d.estado = 'Llegó al Cliente'
              AND ddt.peso_neto_cliente > 0
              AND ddt.ley_humedad_cliente > 0
              AND ddt.ley_oro_cliente > 0
              AND ddt.ley_plata_cliente > 0
            ORDER BY ddt.id DESC
        ";

        $rows = DB::select($sql, ['id_planta' => $idPlanta]);

        // Cargar condiciones comerciales activas de la planta (Oro y Plata)
        // para auto-asignar la condición cuyo rango de ley coincida con
        // la ley cliente reportada en cada distribucion_detalle.
        //
        // Ordenar por ley_inicio DESC: cuando hay solapamiento entre rangos (ej.
        // [0, 0.13] y [0.13, 0.15] ambos inclusivos), gana el rango con inicio
        // más alto (= más específico). Sin desempate, la consulta dependería
        // del orden físico de la tabla, lo cual es no determinista.
        $condicionesPlanta = CondicionComercialPlanta::query()
            ->where('id_planta', $idPlanta)
            ->where('estado', EstadoBase::Activo->value)
            ->get()
            ->sortByDesc('ley_inicio')
            ->values();

        $result = [];
        foreach ($rows as $row) {
            $idDet = (int) $row->id_distribucion_detalle;
            $valorizadoOro = (bool) ($row->esta_valorizado_oro ?? false);
            $valorizadoPlata = (bool) ($row->esta_valorizado_plata ?? false);

            // Buscar condición comercial para Oro: rango [ley_inicio, ley_fin] que
            // contenga la ley_oro_cliente del detalle.
            $leyOroCliente = (float) ($row->ley_oro_cliente ?? 0);
            $condOro = $condicionesPlanta->first(function ($c) use ($leyOroCliente) {
                if ($c->elemento_quimico !== ElementoQuimicoValorizacion::Oro) {
                    return false;
                }
                $inicio = (float) $c->ley_inicio;
                $fin = (float) $c->ley_fin;

                return $leyOroCliente >= $inicio && $leyOroCliente <= $fin;
            });

            // Buscar condición comercial para Plata: rango [ley_inicio, ley_fin] que
            // contenga la ley_plata_cliente del detalle.
            $leyPlataCliente = (float) ($row->ley_plata_cliente ?? 0);
            $condPlata = $condicionesPlanta->first(function ($c) use ($leyPlataCliente) {
                if ($c->elemento_quimico !== ElementoQuimicoValorizacion::Plata) {
                    return false;
                }
                $inicio = (float) $c->ley_inicio;
                $fin = (float) $c->ley_fin;

                return $leyPlataCliente >= $inicio && $leyPlataCliente <= $fin;
            });

            // Si está valorizado por AMBOS elementos, queda excluido salvo que esté
            // vinculado a la valorización que estamos editando.
            if ($valorizadoOro && $valorizadoPlata) {
                if ($idValorizacionEdicion === null) {
                    continue;
                }
                $yaUsado = DB::table('valorizacion_venta_detalle as vvd')
                    ->join('valorizacion_venta as vv', 'vv.id', '=', 'vvd.id_valorizacion_venta')
                    ->where('vvd.id_distribucion_detalle', $idDet)
                    ->where('vv.id', $idValorizacionEdicion)
                    ->exists();
                if (! $yaUsado) {
                    continue;
                }
            }

            $result[] = [
                'id_distribucion_detalle' => $idDet,
                'id_distribucion' => (int) $row->id_distribucion,
                'id_despacho_detalle' => (int) $row->id_despacho_detalle,
                'numero_particion' => $row->numero_particion !== null ? (int) $row->numero_particion : null,
                'peso_neto_cliente' => (float) $row->peso_neto_cliente,
                'ley_oro_cliente' => (float) $row->ley_oro_cliente,
                'ley_plata_cliente' => (float) $row->ley_plata_cliente,
                'ley_humedad_cliente' => (float) $row->ley_humedad_cliente,
                'codigo_cliente' => $row->codigo_cliente !== null ? (string) $row->codigo_cliente : null,
                'esta_valorizado_oro' => $valorizadoOro,
                'esta_valorizado_plata' => $valorizadoPlata,
                'fecha_llegada_cliente' => $row->fecha_llegada_cliente !== null ? (string) $row->fecha_llegada_cliente : null,
                'id_despacho' => (int) $row->id_despacho,
                'despacho_correlativo' => $row->despacho_correlativo !== null ? (string) $row->despacho_correlativo : null,
                'id_planta' => (int) $row->id_planta,
                'planta_nombre' => (string) ($row->planta_nombre ?? ''),
                'id_lote_mineral' => $row->id_lote_mineral !== null ? (int) $row->id_lote_mineral : null,
                'id_blending' => $row->id_blending !== null ? (int) $row->id_blending : null,
                'lote_correlativo' => $row->lote_correlativo !== null ? (string) $row->lote_correlativo : null,
                'lote_ley_oro' => $row->lote_ley_oro !== null ? (float) $row->lote_ley_oro : null,
                'lote_ley_plata' => $row->lote_ley_plata !== null ? (float) $row->lote_ley_plata : null,
                'lote_ley_humedad' => $row->lote_ley_humedad !== null ? (float) $row->lote_ley_humedad : null,
                'blending_correlativo' => $row->blending_correlativo !== null ? (string) $row->blending_correlativo : null,
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
