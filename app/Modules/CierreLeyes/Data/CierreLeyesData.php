<?php

namespace App\Modules\CierreLeyes\Data;

use App\Shared\Enums\_Generic\CondicionIngreso;
use App\Shared\Enums\_Generic\EstadoLeyes;
use App\Shared\Enums\_Generic\EstadoPesaje;
use Illuminate\Support\Facades\DB;

class CierreLeyesData
{
    public static function get_lotes_sugeridos(): array
    {
        $results = DB::select('
            SELECT
                lm.id,
                lm.correlativo,
                lm.numero_correlativo,
                lm.condicion_ingreso,
                lm.peso_neto,
                lm.tipo_mineral,
                lm.estado_leyes,
                lm.created_at
            FROM lote_mineral lm
            INNER JOIN recepcion_unidad ru ON lm.id_recepcion_unidad = ru.id
            WHERE ru.estado_pesaje = "' . EstadoPesaje::Pesado->value . '"
              AND lm.condicion_ingreso = "' . CondicionIngreso::Comercializacion->value . '"
              AND (lm.estado_leyes = "' . EstadoLeyes::Pendiente->value . '" OR lm.estado_leyes IS NULL OR lm.estado_leyes = "")
            ORDER BY lm.created_at DESC, lm.id DESC
        ');

        foreach ($results as $row) {
            $row->id = (int) $row->id;
            $row->numero_correlativo = (int) $row->numero_correlativo;
            $row->peso_neto = $row->peso_neto !== null ? (float) $row->peso_neto : null;
            $row->estado_leyes = $row->estado_leyes !== null ? (string) $row->estado_leyes : null;
        }

        return $results;
    }

    public static function get_lotes_cierre(?int $id = null, array $filtros = []): array
    {
        $estados = $filtros['estados'] ?? [EstadoLeyes::EnProceso->value, EstadoLeyes::Confirmado->value];
        $fechaInicio = $filtros['fecha_inicio'] ?? null;
        $fechaFin = $filtros['fecha_fin'] ?? null;

        $placeholdersEstado = [];
        $bindingsEstado = [];
        foreach ($estados as $idx => $estado) {
            $key = "estado_{$idx}";
            $placeholdersEstado[] = ":{$key}";
            $bindingsEstado[$key] = $estado;
        }

        $sql = '
            SELECT
                lm.id,
                lm.correlativo,
                lm.numero_correlativo,
                lm.condicion_ingreso,
                lm.peso_neto,
                lm.tipo_mineral,
                lm.estado_leyes,
                lm.con_valor_comercial,
                lm.fecha_hora_inicio_analisis,
                lm.fecha_hora_confirmacion_analisis,
                CONCAT(emp_ini.nombre, " ", emp_ini.apellido) AS empleado_inicio_nombre,
                CONCAT(emp_conf.nombre, " ", emp_conf.apellido) AS empleado_confirmacion_nombre
            FROM lote_mineral lm
            LEFT JOIN empleado emp_ini ON lm.id_empleado_inicio_analisis = emp_ini.id
            LEFT JOIN empleado emp_conf ON lm.id_empleado_confirmacion_analisis = emp_conf.id
            WHERE lm.estado_leyes IN (' . implode(',', $placeholdersEstado) . ')
        ';

        $bindings = $bindingsEstado;

        if ($id !== null) {
            $sql .= ' AND lm.id = :id';
            $bindings['id'] = $id;
        }

        if ($fechaInicio !== null) {
            $sql .= ' AND DATE(COALESCE(lm.fecha_hora_inicio_analisis, lm.created_at)) >= :fecha_inicio';
            $bindings['fecha_inicio'] = $fechaInicio;
        }

        if ($fechaFin !== null) {
            $sql .= ' AND DATE(COALESCE(lm.fecha_hora_inicio_analisis, lm.created_at)) <= :fecha_fin';
            $bindings['fecha_fin'] = $fechaFin;
        }

        $sql .= ' ORDER BY lm.id DESC';

        $lotes = DB::select($sql, $bindings);

        foreach ($lotes as $lote) {
            $lote->analisis = DB::select('
                SELECT
                    am.id,
                    am.id_grupo_analisis_detalle,
                    gad.id_grupo_analisis AS id_grupo_analisis,
                    gad.id_analito AS id_analito,
                    am.uuid_fila,
                    am.ley,
                    am.esta_confirmada,
                    am.tipo_origen,
                    am.created_at
                FROM analisis_mineral am
                INNER JOIN grupo_analisis_detalle gad ON am.id_grupo_analisis_detalle = gad.id
                WHERE am.id_lote_mineral = :id_lote_mineral
                ORDER BY am.id ASC
            ', ['id_lote_mineral' => $lote->id]);

            foreach ($lote->analisis as $a) {
                $a->id = (int) $a->id;
                $a->id_grupo_analisis_detalle = (int) $a->id_grupo_analisis_detalle;
                $a->id_grupo_analisis = (int) $a->id_grupo_analisis;
                $a->id_analito = (int) $a->id_analito;
                $a->ley = (float) $a->ley;
                $a->esta_confirmada = (bool) $a->esta_confirmada;
            }

            $lote->id = (int) $lote->id;
            $lote->numero_correlativo = (int) $lote->numero_correlativo;
            $lote->peso_neto = $lote->peso_neto !== null ? (float) $lote->peso_neto : null;
            $lote->con_valor_comercial = $lote->con_valor_comercial !== null ? (bool) $lote->con_valor_comercial : null;
        }

        return $lotes;
    }
}
