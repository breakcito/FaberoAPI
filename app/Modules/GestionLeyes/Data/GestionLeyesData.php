<?php

namespace App\Modules\GestionLeyes\Data;

use App\Models\GrupoAnalisis;
use App\Models\GrupoAnalisisDetalle;
use App\Models\Analito;
use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Support\Facades\DB;

class GestionLeyesData
{
    public static function get_grupos(?int $id = null): array
    {
        $sql = '
        SELECT
            g.id as grupo_id,
            g.nombre as grupo_nombre,
            g.orden as grupo_orden,
            g.indicar_origen as grupo_indicar_origen,
            g.estado as grupo_estado,
            d.id as detalle_id,
            d.id_analito,
            a.nombre as analito_nombre,
            a.es_desplegable as analito_es_desplegable,
            d.para_valorizacion_oro,
            d.para_valorizacion_plata,
            d.para_valorizacion_humedad,
            d.para_valorizacion_recuperacion
        FROM
            grupo_analisis g
        LEFT JOIN
            grupo_analisis_detalle d ON g.id = d.id_grupo_analisis
        LEFT JOIN
            analito a ON d.id_analito = a.id
        WHERE 1 = 1
        ';

        $params = [];
        if ($id !== null) {
            $sql .= ' AND g.id = :id';
            $params['id'] = $id;
        }

        $sql .= ' ORDER BY g.orden ASC, g.id DESC, d.id ASC;';

        $rawResults = DB::select($sql, $params);

        // Agrupar los resultados por grupo
        $grupos = [];
        foreach ($rawResults as $row) {
            $gid = $row->grupo_id;
            if (!isset($grupos[$gid])) {
                $grupos[$gid] = [
                    'id' => $row->grupo_id,
                    'nombre' => $row->grupo_nombre,
                    'orden' => $row->grupo_orden,
                    'indicar_origen' => (bool) $row->grupo_indicar_origen,
                    'estado' => $row->grupo_estado,
                    'analitos' => [],
                ];
            }

            if ($row->id_analito !== null) {
                $grupos[$gid]['analitos'][] = [
                    'detalle_id' => $row->detalle_id,
                    'id_analito' => $row->id_analito,
                    'nombre' => $row->analito_nombre,
                    'es_desplegable' => (bool) $row->analito_es_desplegable,
                    'para_valorizacion_oro' => (bool) $row->para_valorizacion_oro,
                    'para_valorizacion_plata' => (bool) $row->para_valorizacion_plata,
                    'para_valorizacion_humedad' => (bool) $row->para_valorizacion_humedad,
                    'para_valorizacion_recuperacion' => (bool) $row->para_valorizacion_recuperacion,
                ];
            }
        }

        if ($id !== null) {
            return count($grupos) > 0 ? array_values($grupos)[0] : [];
        }

        return array_values($grupos);
    }

    public static function get_grupo_by_id(int $id): array
    {
        return self::get_grupos($id);
    }

    public static function crear_grupo(string $nombre, int $orden, bool $indicarOrigen): int
    {
        return GrupoAnalisis::insertGetId([
            'nombre' => $nombre,
            'orden' => $orden,
            'indicar_origen' => $indicarOrigen ? 1 : 0,
            'estado' => EstadoBase::Activo->value,
        ]);
    }

    public static function editar_grupo(int $id, string $nombre, int $orden, bool $indicarOrigen): bool
    {
        return GrupoAnalisis::where('id', $id)->update([
            'nombre' => $nombre,
            'orden' => $orden,
            'indicar_origen' => $indicarOrigen ? 1 : 0,
        ]) >= 0;
    }

    public static function cambiar_estado_grupo(int $id, string $estado): bool
    {
        return GrupoAnalisis::where('id', $id)->update([
            'estado' => $estado,
        ]) >= 0;
    }

    // Analitos data logic
    public static function get_analitos(): array
    {
        return DB::select('
            SELECT
                id,
                nombre,
                es_desplegable,
                estado
            FROM
                analito
            ORDER BY
                nombre ASC;
        ');
    }

    public static function get_analito_by_id(int $id): array
    {
        $res = DB::selectOne('
            SELECT
                id,
                nombre,
                es_desplegable,
                estado
            FROM
                analito
            WHERE id = :id
        ', ['id' => $id]);

        return $res ? (array)$res : [];
    }

    public static function crear_analito(string $nombre, bool $esDesplegable): int
    {
        return Analito::insertGetId([
            'nombre' => $nombre,
            'es_desplegable' => $esDesplegable ? 1 : 0,
            'estado' => EstadoBase::Activo->value,
        ]);
    }

    public static function cambiar_estado_analito(int $id, string $estado): bool
    {
        return Analito::where('id', $id)->update([
            'estado' => $estado,
        ]) >= 0;
    }

    public static function editar_analito(int $id, string $nombre, bool $esDesplegable): bool
    {
        return Analito::where('id', $id)->update([
            'nombre' => $nombre,
            'es_desplegable' => $esDesplegable ? 1 : 0,
        ]) >= 0;
    }

    // Detail associations
    public static function eliminar_detalles_grupo(int $idGrupoAnalisis): void
    {
        GrupoAnalisisDetalle::where('id_grupo_analisis', $idGrupoAnalisis)->delete();
    }

    public static function agregar_detalle_grupo(
        int $idGrupoAnalisis,
        int $idAnalito,
        bool $oro,
        bool $plata,
        bool $humedad,
        bool $recuperacion
    ): int {
        return GrupoAnalisisDetalle::insertGetId([
            'id_grupo_analisis' => $idGrupoAnalisis,
            'id_analito' => $idAnalito,
            'para_valorizacion_oro' => $oro ? 1 : 0,
            'para_valorizacion_plata' => $plata ? 1 : 0,
            'para_valorizacion_humedad' => $humedad ? 1 : 0,
            'para_valorizacion_recuperacion' => $recuperacion ? 1 : 0,
        ]);
    }
}
