<?php

namespace App\Modules\CierreLeyes\Data;

use App\Models\AnalisisMineral;
use App\Models\GrupoAnalisis;
use App\Models\LoteMineral;
use App\Shared\Enums\_Generic\CondicionIngreso;
use App\Shared\Enums\_Generic\EstadoLeyes;
use App\Shared\Enums\_Generic\EstadoPesaje;
use App\Shared\Responses\_Generic\RES_CambiosLog;
use Illuminate\Support\Facades\DB;

class CierreLeyesData
{
    /**
     * Obtener los lotes sugeridos que están pendientes de grupo de análisis.
     */
    public static function get_lotes_sugeridos(): array
    {
        $results = DB::select('
            SELECT
                lm.id,
                lm.correlativo,
                lm.numero_correlativo,
                lm.condicion_ingreso,
                lm.peso_inicial,
                lm.peso_final,
                lm.peso_neto,
                lm.tipo_mineral,
                lm.id_proveedor_minero,
                pr.razon_social AS proveedor_razon_social,
                lm.created_at
            FROM lote_mineral lm
            LEFT JOIN recepcion_unidad ru ON lm.id_recepcion_unidad = ru.id
            LEFT JOIN proveedor pr ON pr.id = lm.id_proveedor_minero
            WHERE (
                (lm.id_recepcion_unidad IS NOT NULL AND ru.estado_pesaje = "'.EstadoPesaje::Pesado->value.'")
                OR
                (lm.particionado_desde_balanza = 1 AND lm.particion_finalizada = 1 AND lm.id_recepcion_unidad IS NULL AND lm.peso_neto IS NOT NULL)
            )
              AND lm.condicion_ingreso = "'.CondicionIngreso::Comercializacion->value.'"
              AND lm.id NOT IN (
                  SELECT ga.id_lote_mineral 
                  FROM grupo_analisis ga 
                  WHERE ga.id_lote_mineral IS NOT NULL AND ga.id_lote_mineral > 0
              )
            ORDER BY lm.created_at DESC, lm.id DESC
        ');

        foreach ($results as $row) {
            $row->id = (int) $row->id;
            $row->numero_correlativo = (int) $row->numero_correlativo;
            $row->peso_inicial = $row->peso_inicial !== null ? (float) $row->peso_inicial : null;
            $row->peso_final = $row->peso_final !== null ? (float) $row->peso_final : null;
            $row->peso_neto = $row->peso_neto !== null ? (float) $row->peso_neto : null;
            $row->id_proveedor_minero = $row->id_proveedor_minero !== null ? (int) $row->id_proveedor_minero : null;
        }

        return $results;
    }

    /**
     * Obtener el listado de lotes para el cierre de leyes con sus grupos de análisis y análisis hijos.
     *
     * @param  array{fecha_inicio?: string|null, fecha_fin?: string|null}  $filtros
     */
    public static function get_lotes_cierre(?int $id = null, array $filtros = []): array
    {
        $fechaInicio = $filtros['fecha_inicio'] ?? null;
        $fechaFin = $filtros['fecha_fin'] ?? null;

        $sql = '
            SELECT
                lm.id,
                lm.correlativo,
                lm.numero_correlativo,
                lm.condicion_ingreso,
                lm.peso_neto,
                lm.tipo_mineral,
                lm.con_valor_comercial,
                lm.id_proveedor_minero,
                lm.created_at,
                pr.razon_social AS proveedor_razon_social
            FROM lote_mineral lm
            LEFT JOIN recepcion_unidad ru ON lm.id_recepcion_unidad = ru.id
            LEFT JOIN proveedor pr ON pr.id = lm.id_proveedor_minero
            WHERE lm.id IN (
                SELECT DISTINCT ga.id_lote_mineral 
                FROM grupo_analisis ga 
                WHERE ga.id_lote_mineral IS NOT NULL AND ga.id_lote_mineral > 0
            )
              AND lm.condicion_ingreso = "'.CondicionIngreso::Comercializacion->value.'"
        ';

        $bindings = [];

        if ($id !== null) {
            $sql .= ' AND lm.id = :id';
            $bindings['id'] = $id;
        }

        if ($fechaInicio !== null) {
            $sql .= ' AND DATE(lm.created_at) >= :fecha_inicio';
            $bindings['fecha_inicio'] = $fechaInicio;
        }

        if ($fechaFin !== null) {
            $sql .= ' AND DATE(lm.created_at) <= :fecha_fin';
            $bindings['fecha_fin'] = $fechaFin;
        }

        $sql .= ' ORDER BY lm.id DESC';

        $lotes = DB::select($sql, $bindings);

        foreach ($lotes as $lote) {
            $lote->id = (int) $lote->id;
            $lote->numero_correlativo = (int) $lote->numero_correlativo;
            $lote->peso_neto = $lote->peso_neto !== null ? (float) $lote->peso_neto : null;
            $lote->con_valor_comercial = $lote->con_valor_comercial !== null ? (bool) $lote->con_valor_comercial : null;
            $lote->id_proveedor_minero = $lote->id_proveedor_minero !== null ? (int) $lote->id_proveedor_minero : null;

            // Obtener todos los grupos de análisis asociados al lote ordenados por correlativo
            $grupos = DB::select('
                SELECT
                    ga.id,
                    ga.id_empleado_registro,
                    ga.id_lote_mineral,
                    ga.id_muestra_externa,
                    ga.nombre,
                    ga.numero_correlativo,
                    ga.ley_oro_cierre,
                    ga.ley_plata_cierre,
                    ga.ley_humedad_cierre,
                    ga.ley_recuperacion_cierre,
                    ga.log_cambios,
                    ga.tiene_propuesta_oro,
                    ga.tiene_propuesta_plata,
                    ga.created_at,
                    CONCAT(emp.nombre, " ", emp.apellido) AS empleado_registro_nombre,
                    (
                        SELECT COUNT(DISTINCT am_sub.id_muestra_externa)
                        FROM analisis_mineral am_sub
                        WHERE am_sub.id_grupo_analisis = ga.id
                          AND am_sub.id_muestra_externa IS NOT NULL
                          AND am_sub.id_muestra_externa > 0
                    ) AS total_muestras_asociadas,
                    (
                        SELECT vc.estado
                        FROM valorizacion_compramineral_detalle vcd
                        JOIN valorizacion_compra vc ON vc.id = vcd.id_valorizacion_compra
                        WHERE vcd.id_grupo_analisis = ga.id
                          AND vcd.elemento_quimico = "Oro"
                          AND vc.estado != "Anulado"
                        ORDER BY CASE WHEN vc.estado = "Aprobado" THEN 1 ELSE 2 END ASC, vc.id DESC
                        LIMIT 1
                    ) AS estado_valorizacion_oro,
                    (
                        SELECT vc.estado
                        FROM valorizacion_compramineral_detalle vcd
                        JOIN valorizacion_compra vc ON vc.id = vcd.id_valorizacion_compra
                        WHERE vcd.id_grupo_analisis = ga.id
                          AND vcd.elemento_quimico = "Plata"
                          AND vc.estado != "Anulado"
                        ORDER BY CASE WHEN vc.estado = "Aprobado" THEN 1 ELSE 2 END ASC, vc.id DESC
                        LIMIT 1
                    ) AS estado_valorizacion_plata
                FROM grupo_analisis ga
                LEFT JOIN empleado emp ON ga.id_empleado_registro = emp.id
                WHERE ga.id_lote_mineral = :id_lote_mineral
                ORDER BY ga.numero_correlativo ASC, ga.id ASC
            ', ['id_lote_mineral' => $lote->id]);

            foreach ($grupos as $grupo) {
                $grupo->id = (int) $grupo->id;
                $grupo->id_empleado_registro = (int) $grupo->id_empleado_registro;
                $grupo->id_lote_mineral = (int) $grupo->id_lote_mineral;
                $grupo->id_muestra_externa = (int) $grupo->id_muestra_externa;
                $grupo->numero_correlativo = (int) $grupo->numero_correlativo;
                $grupo->ley_oro_cierre = $grupo->ley_oro_cierre !== null ? (float) $grupo->ley_oro_cierre : 0.0;
                $grupo->ley_plata_cierre = $grupo->ley_plata_cierre !== null ? (float) $grupo->ley_plata_cierre : 0.0;
                $grupo->ley_humedad_cierre = $grupo->ley_humedad_cierre !== null ? (float) $grupo->ley_humedad_cierre : 0.0;
                $grupo->ley_recuperacion_cierre = $grupo->ley_recuperacion_cierre !== null ? (float) $grupo->ley_recuperacion_cierre : 0.0;
                $grupo->estado_valorizacion_oro = $grupo->estado_valorizacion_oro ?? ($grupo->tiene_propuesta_oro ? 'Propuesta' : null);
                $grupo->estado_valorizacion_plata = $grupo->estado_valorizacion_plata ?? ($grupo->tiene_propuesta_plata ? 'Propuesta' : null);
                $grupo->tiene_propuesta_oro = ! empty($grupo->estado_valorizacion_oro) || (bool) $grupo->tiene_propuesta_oro;
                $grupo->tiene_propuesta_plata = ! empty($grupo->estado_valorizacion_plata) || (bool) $grupo->tiene_propuesta_plata;
                $grupo->total_muestras_asociadas = (int) ($grupo->total_muestras_asociadas ?? 0);
                $grupo->log_cambios = isset($grupo->log_cambios) ? (is_array($grupo->log_cambios) ? $grupo->log_cambios : (json_decode($grupo->log_cambios, true) ?? [])) : [];

                // Obtener análisis del grupo
                $analisis = DB::select('
                    SELECT
                        am.id,
                        am.id_grupo_analisis,
                        am.id_laboratorio,
                        lab.nombre AS laboratorio_nombre,
                        am.asumido_por,
                        am.numero_ensayo,
                        am.documentos,
                        am.ley_oro,
                        am.ley_plata,
                        am.ley_humedad,
                        am.ley_recuperacion,
                        am.ley_oro_confirmada,
                        am.ley_plata_confirmada,
                        am.tipo_origen,
                        am.sin_lote,
                        am.log_cambios,
                        am.created_at
                    FROM analisis_mineral am
                    LEFT JOIN laboratorio lab ON am.id_laboratorio = lab.id
                    WHERE am.id_grupo_analisis = :id_grupo_analisis
                    ORDER BY am.numero_ensayo ASC, am.id ASC
                ', ['id_grupo_analisis' => $grupo->id]);

                foreach ($analisis as $a) {
                    $a->id = (int) $a->id;
                    $a->id_grupo_analisis = (int) $a->id_grupo_analisis;
                    $a->id_laboratorio = $a->id_laboratorio !== null ? (int) $a->id_laboratorio : null;
                    $a->numero_ensayo = $a->numero_ensayo !== null ? (int) $a->numero_ensayo : 1;
                    $a->ley_oro = (float) $a->ley_oro;
                    $a->ley_plata = (float) $a->ley_plata;
                    $a->ley_humedad = (float) $a->ley_humedad;
                    $a->ley_recuperacion = (float) $a->ley_recuperacion;
                    $a->ley_oro_confirmada = (bool) $a->ley_oro_confirmada;
                    $a->ley_plata_confirmada = (bool) $a->ley_plata_confirmada;
                    $a->documentos = isset($a->documentos) ? (is_array($a->documentos) ? $a->documentos : (json_decode($a->documentos, true) ?? [])) : [];
                    $a->log_cambios = isset($a->log_cambios) ? (is_array($a->log_cambios) ? $a->log_cambios : (json_decode($a->log_cambios, true) ?? [])) : [];
                }

                $grupo->analisis = $analisis;
            }

            $lote->grupos_analisis = $grupos;
            $lote->grupo_analisis = ! empty($grupos) ? $grupos[0] : null;
        }

        return $lotes;
    }

    /**
     * Crear un nuevo grupo de análisis
     */
    public static function crear_grupo(array $data): int
    {
        $idLote = isset($data['id_lote_mineral']) ? (int) $data['id_lote_mineral'] : null;
        $idMuestra = isset($data['id_muestra_externa']) ? (int) $data['id_muestra_externa'] : 0;
        $idEmpleado = (int) $data['id_empleado_registro'];
        $nombre = trim($data['nombre'] ?? 'Grupo 1');

        // Generar correlativo
        $ultimoCorrelativo = DB::table('grupo_analisis')->max('numero_correlativo') ?? 0;
        $nuevoNumero = $ultimoCorrelativo + 1;

        $idGrupo = (int) DB::table('grupo_analisis')->insertGetId([
            'id_empleado_registro' => $idEmpleado,
            'id_lote_mineral' => $idLote ?? 0,
            'id_muestra_externa' => $idMuestra,
            'nombre' => $nombre,
            'numero_correlativo' => $nuevoNumero,
            'ley_oro_cierre' => 0.000,
            'ley_plata_cierre' => 0.000,
            'ley_humedad_cierre' => 0.000,
            'ley_recuperacion_cierre' => 0.000,
            'log_cambios' => isset($data['log_cambios']) ? (is_string($data['log_cambios']) ? $data['log_cambios'] : json_encode($data['log_cambios'])) : json_encode([]),
            'tiene_propuesta_oro' => 0,
            'tiene_propuesta_plata' => 0,
            'created_at' => ! empty($data['created_at']) ? $data['created_at'] : now(),
        ]);

        return $idGrupo;
    }

    /**
     * Agregar un análisis a un grupo
     */
    public static function agregar_analisis_a_grupo(int $idGrupo, array $data, int $idEmpleado): int
    {
        $idLaboratorio = isset($data['id_laboratorio']) ? (int) $data['id_laboratorio'] : null;
        $asumidoPor = isset($data['asumido_por']) ? trim($data['asumido_por']) : 'Proveedor';
        $numeroEnsayo = isset($data['numero_ensayo']) ? (int) $data['numero_ensayo'] : 1;
        $tipoOrigen = isset($data['tipo_origen']) ? trim($data['tipo_origen']) : 'Interno';
        $documentos = isset($data['documentos']) ? (is_array($data['documentos']) ? $data['documentos'] : []) : [];
        $sinLote = isset($data['sin_lote']) ? (bool) $data['sin_lote'] : false;
        $idMuestra = isset($data['id_muestra_externa']) ? (int) $data['id_muestra_externa'] : null;

        $analisisPrevio = DB::table('analisis_mineral')
            ->where('id_grupo_analisis', $idGrupo)
            ->where(function ($q) {
                $q->where('ley_humedad', '>', 0)
                  ->orWhere('ley_recuperacion', '>', 0);
            })
            ->first();

        $grupo = DB::table('grupo_analisis')->where('id', $idGrupo)->first();

        $leyHumedad = $grupo && (float) $grupo->ley_humedad_cierre > 0
            ? (float) $grupo->ley_humedad_cierre
            : ($analisisPrevio ? (float) $analisisPrevio->ley_humedad : 0.000);

        $leyRecuperacion = $grupo && (float) $grupo->ley_recuperacion_cierre > 0
            ? (float) $grupo->ley_recuperacion_cierre
            : ($analisisPrevio ? (float) $analisisPrevio->ley_recuperacion : 0.000);

        return (int) DB::table('analisis_mineral')->insertGetId([
            'id_grupo_analisis' => $idGrupo,
            'id_laboratorio' => $idLaboratorio,
            'asumido_por' => $asumidoPor,
            'numero_ensayo' => $numeroEnsayo,
            'documentos' => json_encode($documentos),
            'ley_oro' => 0.000,
            'ley_plata' => 0.000,
            'ley_humedad' => $leyHumedad,
            'ley_recuperacion' => $leyRecuperacion,
            'ley_oro_confirmada' => 0,
            'ley_plata_confirmada' => 0,
            'id_muestra_externa' => $idMuestra,
            'id_empleado_registro' => $idEmpleado,
            'tipo_origen' => $tipoOrigen,
            'sin_lote' => $sinLote ? 1 : 0,
            'log_cambios' => json_encode([]),
            'created_at' => now(),
        ]);
    }

    /**
     * Guardar valores de leyes en una fila de análisis
     */
    public static function guardar_valores_analisis(
        int $idAnalisis,
        array $valores,
        int $idEmpleado,
        string $motivo = 'Actualización de leyes'
    ): bool {
        $actual = DB::table('analisis_mineral')->where('id', $idAnalisis)->first();
        if (! $actual) {
            return false;
        }

        $cambios = [];
        $updateData = [];

        $campos = [
            'ley_oro' => 'Ley Oro',
            'ley_plata' => 'Ley Plata',
            'ley_humedad' => 'Ley Humedad',
            'ley_recuperacion' => 'Ley Recuperación',
            'ley_oro_confirmada' => 'Confirmación Oro',
            'ley_plata_confirmada' => 'Confirmación Plata',
            'tipo_origen' => 'Tipo Origen',
            'asumido_por' => 'Asumido Por',
            'numero_ensayo' => 'Número Ensayo',
            'id_laboratorio' => 'Laboratorio',
        ];

        foreach ($campos as $campoBd => $nombreCampo) {
            if (array_key_exists($campoBd, $valores)) {
                $valorAnterior = $actual->{$campoBd};
                $valorNuevo = $valores[$campoBd];

                if (in_array($campoBd, ['ley_oro', 'ley_plata', 'ley_humedad', 'ley_recuperacion'])) {
                    $valorNuevo = (float) $valorNuevo;
                    $valorAnterior = (float) $valorAnterior;
                } elseif (in_array($campoBd, ['ley_oro_confirmada', 'ley_plata_confirmada'])) {
                    $valorNuevo = (bool) $valorNuevo ? 1 : 0;
                    $valorAnterior = (bool) $valorAnterior ? 1 : 0;
                }

                if ($valorAnterior != $valorNuevo) {
                    $esConfirmacion = in_array($campoBd, ['ley_oro_confirmada', 'ley_plata_confirmada']);
                    $cambios[] = [
                        'campo_bd' => $campoBd,
                        'campo' => $nombreCampo,
                        'valor_anterior' => $esConfirmacion ? ($valorAnterior ? 'Sí' : 'No') : (string) $valorAnterior,
                        'valor_nuevo' => $esConfirmacion ? ($valorNuevo ? 'Sí' : 'No') : (string) $valorNuevo,
                    ];
                    $updateData[$campoBd] = $valorNuevo;
                }
            }
        }

        if (! empty($cambios)) {
            $logActual = isset($actual->log_cambios) ? (is_array($actual->log_cambios) ? $actual->log_cambios : (json_decode($actual->log_cambios, true) ?? [])) : [];
            $nuevoLog = RES_CambiosLog::crear($idEmpleado, $motivo, $cambios);
            $logActual[] = $nuevoLog;
            $updateData['log_cambios'] = json_encode($logActual);
        }

        if (! empty($updateData)) {
            DB::table('analisis_mineral')->where('id', $idAnalisis)->update($updateData);

            // Si se actualizó humedad o recuperación y pertenece a una muestra externa, mantener sincronizadas todas las filas de esa muestra
            if ($actual->id_muestra_externa && (isset($updateData['ley_humedad']) || isset($updateData['ley_recuperacion']))) {
                $syncMuestra = [];
                if (isset($updateData['ley_humedad'])) {
                    $syncMuestra['ley_humedad'] = $updateData['ley_humedad'];
                }
                if (isset($updateData['ley_recuperacion'])) {
                    $syncMuestra['ley_recuperacion'] = $updateData['ley_recuperacion'];
                }
                DB::table('analisis_mineral')
                    ->where('id_muestra_externa', $actual->id_muestra_externa)
                    ->where('id', '!=', $idAnalisis)
                    ->update($syncMuestra);
            }
        }

        return true;
    }

    /**
     * Guardar leyes de cierre en el grupo de análisis
     */
    public static function guardar_leyes_cierre_grupo(
        int $idGrupo,
        array $leyes,
        int $idEmpleado,
        string $motivo = 'Cierre de leyes de grupo'
    ): bool {
        $actual = DB::table('grupo_analisis')->where('id', $idGrupo)->first();
        if (! $actual) {
            return false;
        }

        $cambios = [];
        $updateData = [];

        $campos = [
            'ley_oro_cierre' => 'Ley Oro Cierre',
            'ley_plata_cierre' => 'Ley Plata Cierre',
            'ley_humedad_cierre' => 'Ley Humedad Cierre',
            'ley_recuperacion_cierre' => 'Ley Recuperación Cierre',
        ];

        foreach ($campos as $campoBd => $nombreCampo) {
            if (array_key_exists($campoBd, $leyes)) {
                $valorAnterior = (float) ($actual->{$campoBd} ?? 0);
                $valorNuevo = (float) $leyes[$campoBd];

                if ($valorAnterior !== $valorNuevo) {
                    $cambios[] = [
                        'campo_bd' => $campoBd,
                        'campo' => $nombreCampo,
                        'valor_anterior' => (string) $valorAnterior,
                        'valor_nuevo' => (string) $valorNuevo,
                    ];
                    $updateData[$campoBd] = $valorNuevo;
                }
            }
        }

        if (! empty($cambios)) {
            $logActual = isset($actual->log_cambios) ? (is_array($actual->log_cambios) ? $actual->log_cambios : (json_decode($actual->log_cambios, true) ?? [])) : [];
            $nuevoLog = RES_CambiosLog::crear($idEmpleado, $motivo, $cambios);
            $logActual[] = $nuevoLog;
            $updateData['log_cambios'] = json_encode($logActual);
        }

        if (! empty($updateData)) {
            DB::table('grupo_analisis')->where('id', $idGrupo)->update($updateData);
        }

        return true;
    }

    /**
     * Adjuntar nuevos documentos a un análisis
     */
    public static function adjuntar_documentos_analisis(int $idAnalisis, array $nuevosDocs): array|false
    {
        $actual = DB::table('analisis_mineral')->where('id', $idAnalisis)->first();
        if (! $actual) {
            return false;
        }

        $docsActuales = isset($actual->documentos) ? (is_array($actual->documentos) ? $actual->documentos : (json_decode($actual->documentos, true) ?? [])) : [];
        $docsFinales = array_merge($docsActuales, $nuevosDocs);

        DB::table('analisis_mineral')->where('id', $idAnalisis)->update([
            'documentos' => json_encode($docsFinales),
        ]);

        return $docsFinales;
    }

    /**
     * Eliminar análisis
     */
    public static function eliminar_analisis(int $idAnalisis): bool
    {
        return DB::table('analisis_mineral')->where('id', $idAnalisis)->delete() > 0;
    }

    /**
     * Confirmar cierre del lote mineral (indicador informativo de valor comercial)
     */
    public static function confirmar_lote(int $idLote, bool $conValorComercial, int $idEmpleado): bool
    {
        return DB::table('lote_mineral')->where('id', $idLote)->update([
            'con_valor_comercial' => $conValorComercial ? 1 : 0,
        ]) >= 0;
    }

    /**
     * Muestras externas pendientes
     */
    public static function get_muestras_externas(): array
    {
        $muestras = DB::select('
            SELECT
                me.id,
                me.id AS id_muestra_externa,
                me.id_empleado_registro,
                me.id_proveedor_minero AS id_proveedor,
                p.razon_social AS proveedor_nombre,
                me.correlativo,
                me.numero_correlativo,
                me.codigo_cliente,
                COALESCE(me.codigo_cliente, me.correlativo) AS nombre,
                me.fecha_hora_ingreso,
                me.created_at,
                CONCAT(emp.nombre, " ", emp.apellido) AS empleado_registro_nombre
            FROM muestra_externa me
            LEFT JOIN proveedor p ON me.id_proveedor_minero = p.id
            LEFT JOIN empleado emp ON me.id_empleado_registro = emp.id
            WHERE NOT EXISTS (
                SELECT 1 FROM analisis_mineral am
                WHERE am.id_muestra_externa = me.id
                  AND am.id_grupo_analisis IS NOT NULL
                  AND am.id_grupo_analisis > 0
            )
            ORDER BY me.id DESC
        ');

        foreach ($muestras as $m) {
            $m->id = (int) $m->id;
            $m->id_muestra_externa = (int) $m->id_muestra_externa;
            $m->id_empleado_registro = (int) $m->id_empleado_registro;
            $m->numero_correlativo = (int) ($m->numero_correlativo ?? $m->id);
            $m->id_proveedor = ! empty($m->id_proveedor) ? (int) $m->id_proveedor : null;

            $analisis = DB::select('
                SELECT
                    am.id,
                    am.id_grupo_analisis,
                    am.id_muestra_externa,
                    am.id_laboratorio,
                    lab.nombre AS laboratorio_nombre,
                    am.asumido_por,
                    am.numero_ensayo,
                    am.documentos,
                    am.ley_oro,
                    am.ley_plata,
                    am.ley_humedad,
                    am.ley_recuperacion,
                    am.ley_oro_confirmada,
                    am.ley_plata_confirmada,
                    am.tipo_origen,
                    am.sin_lote,
                    am.log_cambios,
                    am.created_at
                FROM analisis_mineral am
                LEFT JOIN laboratorio lab ON am.id_laboratorio = lab.id
                WHERE am.id_muestra_externa = :id_muestra
                  AND (am.id_grupo_analisis IS NULL OR am.id_grupo_analisis = 0)
                ORDER BY am.numero_ensayo ASC, am.id ASC
            ', ['id_muestra' => $m->id]);

            foreach ($analisis as $a) {
                $a->id = (int) $a->id;
                $a->id_grupo_analisis = $a->id_grupo_analisis ? (int) $a->id_grupo_analisis : null;
                $a->id_muestra_externa = $a->id_muestra_externa ? (int) $a->id_muestra_externa : null;
                $a->id_laboratorio = $a->id_laboratorio !== null ? (int) $a->id_laboratorio : null;
                $a->numero_ensayo = $a->numero_ensayo !== null ? (int) $a->numero_ensayo : 1;
                $a->ley_oro = (float) $a->ley_oro;
                $a->ley_plata = (float) $a->ley_plata;
                $a->ley_humedad = (float) $a->ley_humedad;
                $a->ley_recuperacion = (float) $a->ley_recuperacion;
                $a->ley_oro_confirmada = (bool) $a->ley_oro_confirmada;
                $a->ley_plata_confirmada = (bool) $a->ley_plata_confirmada;
                $a->sin_lote = (bool) $a->sin_lote;
                $a->documentos = isset($a->documentos) ? (is_array($a->documentos) ? $a->documentos : (json_decode($a->documentos, true) ?? [])) : [];
                $a->log_cambios = isset($a->log_cambios) ? (is_array($a->log_cambios) ? $a->log_cambios : (json_decode($a->log_cambios, true) ?? [])) : [];
            }

            $m->analisis = $analisis;
        }

        return $muestras;
    }

    /**
     * Asociar múltiples muestras externas a un grupo de análisis de un lote calculando promedios de humedad y recuperación
     *
     * @param  int[]  $idsMuestraExterna
     */
    public static function asociar_muestras_multiples_a_grupo(array $idsMuestraExterna, int $idGrupoDestino): bool
    {
        $grupoDestino = DB::table('grupo_analisis')->where('id', $idGrupoDestino)->first();
        if (! $grupoDestino) {
            return false;
        }

        // 1. Obtener valores de humedad y recuperación del grupo o de sus análisis actuales
        $humedadGrupo = (float) ($grupoDestino->ley_humedad_cierre ?? 0);
        $recupGrupo = (float) ($grupoDestino->ley_recuperacion_cierre ?? 0);

        $existentesAnalisisGrupo = DB::table('analisis_mineral')
            ->where('id_grupo_analisis', $idGrupoDestino)
            ->get();

        if ($humedadGrupo <= 0) {
            foreach ($existentesAnalisisGrupo as $eg) {
                if ((float) $eg->ley_humedad > 0) {
                    $humedadGrupo = (float) $eg->ley_humedad;
                    break;
                }
            }
        }

        if ($recupGrupo <= 0) {
            foreach ($existentesAnalisisGrupo as $eg) {
                if ((float) $eg->ley_recuperacion > 0) {
                    $recupGrupo = (float) $eg->ley_recuperacion;
                    break;
                }
            }
        }

        // 2. Recolectar valores válidos (> 0) de humedad y recuperación
        $valoresHumedad = [];
        if ($humedadGrupo > 0) {
            $valoresHumedad[] = $humedadGrupo;
        }

        $valoresRecuperacion = [];
        if ($recupGrupo > 0) {
            $valoresRecuperacion[] = $recupGrupo;
        }

        $analisisMuestras = DB::table('analisis_mineral')
            ->whereIn('id_muestra_externa', $idsMuestraExterna)
            ->get();

        foreach ($analisisMuestras as $am) {
            $h = (float) $am->ley_humedad;
            if ($h > 0) {
                $valoresHumedad[] = $h;
            }
            $r = (float) $am->ley_recuperacion;
            if ($r > 0) {
                $valoresRecuperacion[] = $r;
            }
        }

        // 3. Calcular promedios de los valores válidos (> 0)
        $promedioHumedad = count($valoresHumedad) > 0 ? (array_sum($valoresHumedad) / count($valoresHumedad)) : null;
        $promedioRecuperacion = count($valoresRecuperacion) > 0 ? (array_sum($valoresRecuperacion) / count($valoresRecuperacion)) : null;

        // 4. Asociar análisis de cada muestra al grupo destino
        $maxEnsayo = (int) (DB::table('analisis_mineral')
            ->where('id_grupo_analisis', $idGrupoDestino)
            ->max('numero_ensayo') ?? 0);

        foreach ($idsMuestraExterna as $idMuestra) {
            $idMuestra = (int) $idMuestra;
            $analisisMuestra = DB::table('analisis_mineral')
                ->where('id_muestra_externa', $idMuestra)
                ->orderBy('id', 'asc')
                ->get();

            if ($analisisMuestra->count() > 0) {
                foreach ($analisisMuestra as $am) {
                    $maxEnsayo++;
                    DB::table('analisis_mineral')
                        ->where('id', $am->id)
                        ->update([
                            'id_grupo_analisis' => $idGrupoDestino,
                            'numero_ensayo' => $maxEnsayo,
                            'sin_lote' => 0,
                        ]);
                }
            } else {
                $yaAsociado = DB::table('analisis_mineral')
                    ->where('id_muestra_externa', $idMuestra)
                    ->where('id_grupo_analisis', $idGrupoDestino)
                    ->exists();

                if (! $yaAsociado) {
                    $maxEnsayo++;
                    DB::table('analisis_mineral')->insert([
                        'id_grupo_analisis' => $idGrupoDestino,
                        'id_muestra_externa' => $idMuestra,
                        'id_laboratorio' => null,
                        'asumido_por' => 'Proveedor',
                        'numero_ensayo' => $maxEnsayo,
                        'tipo_origen' => 'Externo',
                        'sin_lote' => 0,
                        'documentos' => json_encode([]),
                        'ley_oro' => 0.000,
                        'ley_plata' => 0.000,
                        'ley_humedad' => 0.000,
                        'ley_recuperacion' => 0.000,
                        'ley_oro_confirmada' => 0,
                        'ley_plata_confirmada' => 0,
                        'id_empleado_registro' => (int) (auth()->id() ?? 1),
                        'log_cambios' => json_encode([]),
                        'created_at' => now(),
                    ]);
                }
            }
        }

        // 5. Aplicar los promedios al grupo de análisis y a todos los análisis del grupo
        $updateGrupo = [];
        if ($promedioHumedad !== null) {
            $promHumedad = round($promedioHumedad, 3);
            $updateGrupo['ley_humedad_cierre'] = $promHumedad;
            DB::table('analisis_mineral')
                ->where('id_grupo_analisis', $idGrupoDestino)
                ->update(['ley_humedad' => $promHumedad]);
        }

        if ($promedioRecuperacion !== null) {
            $promRecup = round($promedioRecuperacion, 3);
            $updateGrupo['ley_recuperacion_cierre'] = $promRecup;
            DB::table('analisis_mineral')
                ->where('id_grupo_analisis', $idGrupoDestino)
                ->update(['ley_recuperacion' => $promRecup]);
        }

        if (! empty($updateGrupo)) {
            DB::table('grupo_analisis')
                ->where('id', $idGrupoDestino)
                ->update($updateGrupo);
        }

        return true;
    }

    /**
     * Asociar muestra externa a un grupo de análisis de un lote
     */
    public static function asociar_muestra_a_grupo(int $idMuestraExterna, int $idGrupoDestino): bool
    {
        return self::asociar_muestras_multiples_a_grupo([$idMuestraExterna], $idGrupoDestino);
    }

    /**
     * Obtener muestras asociadas por grupo de análisis agrupadas por muestra
     */
    public static function get_muestras_asociadas_por_grupo(int $idGrupo): array
    {
        return DB::select('
            SELECT
                me.id AS id_muestra_externa,
                COALESCE(me.codigo_cliente, me.correlativo) AS codigo_cliente,
                p.razon_social AS proveedor_nombre,
                GROUP_CONCAT(DISTINCT COALESCE(lab.nombre, "Sin Laboratorio") ORDER BY lab.nombre SEPARATOR ", ") AS laboratorio_nombre,
                COUNT(am.id) AS total_ensayos,
                MIN(am.created_at) AS created_at
            FROM analisis_mineral am
            INNER JOIN muestra_externa me ON am.id_muestra_externa = me.id
            LEFT JOIN proveedor p ON me.id_proveedor_minero = p.id
            LEFT JOIN laboratorio lab ON am.id_laboratorio = lab.id
            WHERE am.id_grupo_analisis = :id_grupo
              AND am.id_muestra_externa IS NOT NULL
              AND am.id_muestra_externa > 0
            GROUP BY me.id, me.codigo_cliente, me.correlativo, p.razon_social
            ORDER BY me.id ASC
        ', ['id_grupo' => $idGrupo]);
    }
}
