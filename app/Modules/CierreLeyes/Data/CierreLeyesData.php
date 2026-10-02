<?php

namespace App\Modules\CierreLeyes\Data;

use App\Models\AnalisisMineral;
use App\Models\GrupoAnalisisDetalle;
use App\Models\LoteMineral;
use App\Shared\Enums\_Generic\CondicionIngreso;
use App\Shared\Enums\_Generic\EstadoLeyes;
use App\Shared\Enums\_Generic\EstadoPesaje;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CierreLeyesData
{
    /**
     * Obtener los lotes sugeridos que están pendientes de análisis de leyes.
     * Solo retorna los pesos oficiales (pueden ser NULL si la guia aun no los seteo).
     */
    public static function get_lotes_sugeridos(): array
    {
        $results = DB::select('
            SELECT
                lm.id,
                lm.correlativo,
                lm.numero_correlativo,
                lm.condicion_ingreso,
                COALESCE(lm.peso_inicial_oficial, lm.peso_inicial) AS peso_inicial,
                COALESCE(lm.peso_final_oficial, lm.peso_final) AS peso_final,
                COALESCE(lm.peso_neto_oficial, lm.peso_neto) AS peso_neto,
                lm.tipo_mineral,
                lm.estado_leyes,
                lm.created_at
            FROM lote_mineral lm
            LEFT JOIN recepcion_unidad ru ON lm.id_recepcion_unidad = ru.id
            WHERE (
                -- Lote regular: tiene unidad y está pesado.
                (lm.id_recepcion_unidad IS NOT NULL
                 AND ru.estado_pesaje = "'.EstadoPesaje::Pesado->value.'")
                OR
                -- Lote padre particionado y finalizado: no tiene unidad directa,
                -- pero el total consolidado ya fue sellado por finalizar_particion_lote.
                (lm.particionado_desde_balanza = 1
                 AND lm.particion_finalizada = 1
                 AND lm.id_recepcion_unidad IS NULL
                 AND lm.peso_neto_oficial IS NOT NULL)
            )
              AND lm.condicion_ingreso = "'.CondicionIngreso::Comercializacion->value.'"
              AND (lm.estado_leyes = "'.EstadoLeyes::Pendiente->value.'" OR lm.estado_leyes IS NULL OR lm.estado_leyes = "")
            ORDER BY lm.created_at DESC, lm.id DESC
        ');

        foreach ($results as $row) {
            $row->id = (int) $row->id;
            $row->numero_correlativo = (int) $row->numero_correlativo;
            $row->peso_inicial = $row->peso_inicial !== null ? (float) $row->peso_inicial : null;
            $row->peso_final = $row->peso_final !== null ? (float) $row->peso_final : null;
            $row->peso_neto = $row->peso_neto !== null ? (float) $row->peso_neto : null;
            $row->estado_leyes = $row->estado_leyes !== null ? (string) $row->estado_leyes : null;
        }

        return $results;
    }

    /**
     * Obtener el listado de lotes para el cierre de leyes con sus análisis asociados.
     *
     * @param  array{estados?: string[], fecha_inicio?: string|null, fecha_fin?: string|null}  $filtros
     */
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
                COALESCE(lm.peso_neto_oficial, lm.peso_neto) AS peso_neto,
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
            WHERE lm.estado_leyes IN ('.implode(',', $placeholdersEstado).')
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
                    am.log_cambios,
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
                $a->log_cambios = isset($a->log_cambios) ? (is_array($a->log_cambios) ? $a->log_cambios : (json_decode($a->log_cambios, true) ?? [])) : [];
            }

            $lote->id = (int) $lote->id;
            $lote->numero_correlativo = (int) $lote->numero_correlativo;
            $lote->peso_neto = $lote->peso_neto !== null ? (float) $lote->peso_neto : null;
            $lote->con_valor_comercial = $lote->con_valor_comercial !== null ? (bool) $lote->con_valor_comercial : null;
        }

        return $lotes;
    }

    /**
     * Obtener un lote mineral por su ID.
     */
    public static function get_lote_by_id(int $idLote): ?LoteMineral
    {
        return LoteMineral::find($idLote);
    }

    /**
     * Obtener los detalles de grupos de análisis activos.
     */
    public static function get_detalles_activos_analisis(): array
    {
        return DB::table('grupo_analisis_detalle as gad')
            ->join('grupo_analisis as ga', 'gad.id_grupo_analisis', '=', 'ga.id')
            ->where('ga.estado', 'Activo')
            ->select('gad.id as detalle_id', 'ga.indicar_origen')
            ->get()
            ->toArray();
    }

    /**
     * Obtener los detalles de grupos de análisis activos que están marcados para valorización.
     */
    public static function get_detalles_para_valorizacion(): array
    {
        return DB::table('grupo_analisis_detalle as gad')
            ->join('grupo_analisis as ga', 'gad.id_grupo_analisis', '=', 'ga.id')
            ->join('analito as an', 'gad.id_analito', '=', 'an.id')
            ->where('ga.estado', 'Activo')
            ->where(function ($query) {
                $query->where('gad.para_valorizacion_oro', 1)
                    ->orWhere('gad.para_valorizacion_plata', 1)
                    ->orWhere('gad.para_valorizacion_humedad', 1)
                    ->orWhere('gad.para_valorizacion_recuperacion', 1);
            })
            ->select('gad.id as detalle_id', 'an.nombre as analito_nombre')
            ->get()
            ->toArray();
    }

    /**
     * Obtener un detalle de grupo de análisis por su ID incluyendo la relación con su analito.
     */
    public static function get_detalle_con_analito(int $idGrupoAnalisisDetalle): ?GrupoAnalisisDetalle
    {
        return GrupoAnalisisDetalle::with('analito')->find($idGrupoAnalisisDetalle);
    }

    /**
     * Crear registros iniciales vacíos de análisis para un lote.
     */
    public static function crear_registros_vacios_analisis(int $idLote, string $uuidFila, int $idEmpleado): void
    {
        $detallesActivos = self::get_detalles_activos_analisis();

        foreach ($detallesActivos as $detalle) {
            AnalisisMineral::create([
                'id_lote_mineral' => $idLote,
                'id_grupo_analisis_detalle' => $detalle->detalle_id,
                'tipo_origen' => null,
                'uuid_fila' => $uuidFila,
                'ley' => 0.0,
                'esta_confirmada' => 0,
                'id_empleado_registro' => $idEmpleado,
            ]);
        }
    }

    /**
     * Actualizar el estado de un lote a En Proceso al iniciar el análisis.
     */
    public static function actualizar_estado_inicio_lote(LoteMineral $lote, int $idEmpleado): void
    {
        $lote->estado_leyes = EstadoLeyes::EnProceso;
        $lote->id_empleado_inicio_analisis = $idEmpleado;
        $lote->fecha_hora_inicio_analisis = Carbon::now();
        $lote->save();
    }

    /**
     * Generar un log de cambio para una entidad AnalisisMineral.
     */
    public static function generar_log_cambio(
        AnalisisMineral $registro,
        ?float $nuevaLey,
        ?bool $nuevaEstaConfirmada,
        ?string $nuevoTipoOrigen,
        int $idEmpleado
    ): void {
        $cambios = [];

        if ($nuevaLey !== null && (float) $registro->ley !== (float) $nuevaLey) {
            $cambios[] = [
                'campo_bd' => 'ley',
                'campo' => 'Ley',
                'valor_anterior' => (float) $registro->ley,
                'valor_nuevo' => (float) $nuevaLey,
            ];
        }

        if ($nuevaEstaConfirmada !== null && (bool) $registro->esta_confirmada !== (bool) $nuevaEstaConfirmada) {
            $cambios[] = [
                'campo_bd' => 'esta_confirmada',
                'campo' => 'Estado Confirmado',
                'valor_anterior' => (bool) $registro->esta_confirmada,
                'valor_nuevo' => (bool) $nuevaEstaConfirmada,
            ];
        }

        if ($nuevoTipoOrigen !== null && $registro->tipo_origen !== $nuevoTipoOrigen) {
            $cambios[] = [
                'campo_bd' => 'tipo_origen',
                'campo' => 'Tipo de origen',
                'valor_anterior' => $registro->tipo_origen ?? '—',
                'valor_nuevo' => $nuevoTipoOrigen ?? '—',
            ];
        }

        if (! empty($cambios)) {
            $logActual = $registro->log_cambios ?? [];
            if (! is_array($logActual)) {
                $logActual = json_decode($logActual, true) ?? [];
            }
            $nuevoEntry = [
                'id_empleado' => $idEmpleado,
                'motivo' => null,
                'update_at' => Carbon::now()->toDateTimeString(),
                'cambios' => $cambios,
            ];
            array_unshift($logActual, $nuevoEntry);
            $registro->log_cambios = $logActual;
        }
    }

    /**
     * Actualizar todas las filas de un grupo de análisis no desplegable en un lote.
     */
    public static function actualizar_leyes_no_desplegables(
        int $idLoteMineral,
        int $idGrupoAnalisisDetalle,
        float $ley,
        bool $estaConfirmada,
        int $idEmpleadoRegistro
    ): int {
        $registros = AnalisisMineral::where('id_lote_mineral', $idLoteMineral)
            ->where('id_grupo_analisis_detalle', $idGrupoAnalisisDetalle)
            ->get();

        if ($registros->isEmpty()) {
            return 0;
        }

        foreach ($registros as $registro) {
            self::generar_log_cambio($registro, $ley, $estaConfirmada, null, $idEmpleadoRegistro);
            $registro->ley = $ley;
            $registro->esta_confirmada = $estaConfirmada ? 1 : 0;
            $registro->id_empleado_registro = $idEmpleadoRegistro;
            $registro->save();
        }

        return $registros->count();
    }

    /**
     * Crear un nuevo registro en la tabla analisis_mineral.
     *
     * @param  array{id_lote_mineral: int, id_grupo_analisis_detalle: int, tipo_origen: string|null, uuid_fila: string, ley: float, esta_confirmada: int, id_empleado_registro: int}  $datos
     */
    public static function crear_analisis_mineral(array $datos): AnalisisMineral
    {
        $ley = isset($datos['ley']) ? (float) $datos['ley'] : 0.0;
        $estaConfirmada = isset($datos['esta_confirmada']) ? (bool) $datos['esta_confirmada'] : false;
        $tipoOrigen = $datos['tipo_origen'] ?? null;
        $idEmpleado = $datos['id_empleado_registro'] ?? 1;

        $cambios = [];

        if ($ley > 0) {
            $cambios[] = [
                'campo_bd' => 'ley',
                'campo' => 'Ley',
                'valor_anterior' => 0.0,
                'valor_nuevo' => $ley,
            ];
        }

        if ($estaConfirmada) {
            $cambios[] = [
                'campo_bd' => 'esta_confirmada',
                'campo' => 'Estado Confirmado',
                'valor_anterior' => false,
                'valor_nuevo' => true,
            ];
        }

        if ($tipoOrigen !== null) {
            $cambios[] = [
                'campo_bd' => 'tipo_origen',
                'campo' => 'Tipo de origen',
                'valor_anterior' => '—',
                'valor_nuevo' => $tipoOrigen,
            ];
        }

        if (! empty($cambios)) {
            $datos['log_cambios'] = [
                [
                    'id_empleado' => $idEmpleado,
                    'motivo' => null,
                    'update_at' => Carbon::now()->toDateTimeString(),
                    'cambios' => $cambios,
                ],
            ];
        }

        return AnalisisMineral::create($datos);
    }

    /**
     * Obtener un registro individual de analisis_mineral por su ID.
     */
    public static function get_registro_analisis_by_id(int $id): ?AnalisisMineral
    {
        return AnalisisMineral::find($id);
    }

    /**
     * Actualizar un registro existente de analisis_mineral.
     */
    public static function actualizar_registro_analisis(
        AnalisisMineral $registro,
        float $ley,
        bool $estaConfirmada,
        int $idEmpleadoRegistro
    ): void {
        self::generar_log_cambio($registro, $ley, $estaConfirmada, null, $idEmpleadoRegistro);
        $registro->ley = $ley;
        $registro->esta_confirmada = $estaConfirmada ? 1 : 0;
        $registro->id_empleado_registro = $idEmpleadoRegistro;
        $registro->save();
    }

    /**
     * Eliminar un registro individual de analisis_mineral.
     */
    public static function eliminar_registro_analisis(AnalisisMineral $registro): void
    {
        $registro->delete();
    }

    /**
     * Eliminar todas las celdas de análisis de una corrida por uuid_fila.
     */
    public static function eliminar_fila_analisis(int $idLoteMineral, string $uuidFila): void
    {
        AnalisisMineral::where('id_lote_mineral', $idLoteMineral)
            ->where('uuid_fila', $uuidFila)
            ->delete();
    }

    /**
     * Actualizar el tipo de origen para todas las celdas de una corrida de análisis.
     */
    public static function actualizar_origen_fila(int $idLoteMineral, string $uuidFila, ?string $tipoOrigen, int $idEmpleado = 1): void
    {
        $registros = AnalisisMineral::where('id_lote_mineral', $idLoteMineral)
            ->where('uuid_fila', $uuidFila)
            ->get();

        foreach ($registros as $registro) {
            self::generar_log_cambio($registro, null, null, $tipoOrigen, $idEmpleado);
            $registro->tipo_origen = $tipoOrigen;
            $registro->save();
        }
    }

    /**
     * Obtener todas las filas de analisis_mineral pertenecientes a un lote.
     */
    public static function get_filas_analisis_por_lote(int $idLote): Collection
    {
        return AnalisisMineral::where('id_lote_mineral', $idLote)->get();
    }

    /**
     * Obtener únicamente las filas de analisis_mineral confirmadas para un lote.
     */
    public static function get_analisis_confirmados_por_lote(int $idLote): Collection
    {
        return AnalisisMineral::where('id_lote_mineral', $idLote)
            ->where('esta_confirmada', 1)
            ->get();
    }

    /**
     * Actualizar el lote mineral al confirmar y cerrar las leyes.
     *
     * @param  array{ley_oro: float, ley_plata: float, ley_humedad: float, ley_recuperacion: float}  $leyesValores
     */
    public static function confirmar_y_cerrar_lote(
        LoteMineral $lote,
        array $leyesValores,
        bool $conValorComercial,
        int $idEmpleado
    ): void {
        $lote->ley_oro = $leyesValores['ley_oro'];
        $lote->ley_plata = $leyesValores['ley_plata'];
        $lote->ley_humedad = $leyesValores['ley_humedad'];
        $lote->ley_recuperacion = $leyesValores['ley_recuperacion'];

        $lote->estado_leyes = EstadoLeyes::Confirmado;
        $lote->con_valor_comercial = $conValorComercial ? 1 : 0;
        $lote->id_empleado_confirmacion_analisis = $idEmpleado;
        $lote->fecha_hora_confirmacion_analisis = Carbon::now();
        $lote->save();
    }

    // ===== MUESTRAS EXTERNAS =====

    /**
     * Crear la cabecera de una muestra externa con su correlativo.
     *
     * @param  array{id_empleado_registro: int, id_proveedor_minero: int, correlativo: string, numero_correlativo: int}  $datos
     */
    public static function crear_muestra_externa(array $datos): int
    {
        return (int) DB::table('muestra_externa')->insertGetId([
            'id_empleado_registro' => $datos['id_empleado_registro'],
            'id_proveedor_minero' => $datos['id_proveedor_minero'],
            'correlativo' => $datos['correlativo'],
            'numero_correlativo' => $datos['numero_correlativo'],
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * Crear registros iniciales de analisis_mineral para una muestra externa (sin lote asociado).
     */
    public static function crear_registros_vacios_analisis_muestra(int $idMuestra, string $uuidFila, int $idEmpleado): void
    {
        $detallesActivos = self::get_detalles_activos_analisis();

        foreach ($detallesActivos as $detalle) {
            AnalisisMineral::create([
                'id_lote_mineral' => null,
                'id_muestra_externa' => $idMuestra,
                'id_grupo_analisis_detalle' => $detalle->detalle_id,
                'tipo_origen' => null,
                'uuid_fila' => $uuidFila,
                'ley' => 0.0,
                'esta_confirmada' => 0,
                'id_empleado_registro' => $idEmpleado,
                'sin_lote' => 1,
            ]);
        }
    }

    /**
     * Obtener una muestra externa por ID con cabecera, proveedor y análisis asociados.
     */
    public static function get_muestra_externa_by_id(int $idMuestra): ?array
    {
        $row = DB::selectOne('
            SELECT
                me.id,
                me.id_empleado_registro,
                me.id_proveedor_minero,
                me.correlativo,
                me.numero_correlativo,
                me.created_at,
                p.razon_social AS proveedor_razon_social,
                CONCAT(emp.nombre, " ", emp.apellido) AS empleado_registro_nombre
            FROM muestra_externa me
            LEFT JOIN proveedor p ON me.id_proveedor_minero = p.id
            LEFT JOIN empleado emp ON me.id_empleado_registro = emp.id
            WHERE me.id = :id
        ', ['id' => $idMuestra]);

        if (! $row) {
            return null;
        }

        $row->id = (int) $row->id;
        $row->id_empleado_registro = (int) $row->id_empleado_registro;
        $row->id_proveedor_minero = (int) $row->id_proveedor_minero;
        $row->numero_correlativo = (int) $row->numero_correlativo;

        $row->analisis = DB::select('
            SELECT
                am.id,
                am.id_grupo_analisis_detalle,
                gad.id_grupo_analisis AS id_grupo_analisis,
                gad.id_analito AS id_analito,
                am.uuid_fila,
                am.ley,
                am.esta_confirmada,
                am.tipo_origen,
                am.log_cambios,
                am.created_at
            FROM analisis_mineral am
            INNER JOIN grupo_analisis_detalle gad ON am.id_grupo_analisis_detalle = gad.id
            WHERE am.id_muestra_externa = :id
            ORDER BY am.id ASC
        ', ['id' => $idMuestra]);

        foreach ($row->analisis as $a) {
            $a->id = (int) $a->id;
            $a->id_grupo_analisis_detalle = (int) $a->id_grupo_analisis_detalle;
            $a->id_grupo_analisis = (int) $a->id_grupo_analisis;
            $a->id_analito = (int) $a->id_analito;
            $a->ley = (float) $a->ley;
            $a->esta_confirmada = (bool) $a->esta_confirmada;
            $a->log_cambios = isset($a->log_cambios) ? (is_array($a->log_cambios) ? $a->log_cambios : (json_decode($a->log_cambios, true) ?? [])) : [];
        }

        return (array) $row;
    }

    /**
     * Listar las muestras externas activas: las que aún tienen al menos un análisis
     * con sin_lote=1 (todavía no migrado a lote). Si se borraron todos los análisis
     * o se migraron todos, la muestra NO aparece (se considera "consumida").
     */
    public static function get_muestras_externas_activas(): array
    {
        $rows = DB::select('
            SELECT
                me.id,
                me.id_empleado_registro,
                me.id_proveedor_minero,
                me.correlativo,
                me.numero_correlativo,
                me.created_at,
                p.razon_social AS proveedor_razon_social,
                CONCAT(emp.nombre, " ", emp.apellido) AS empleado_registro_nombre
            FROM muestra_externa me
            LEFT JOIN proveedor p ON me.id_proveedor_minero = p.id
            LEFT JOIN empleado emp ON me.id_empleado_registro = emp.id
            WHERE EXISTS (
                SELECT 1 FROM analisis_mineral am
                WHERE am.id_muestra_externa = me.id
                  AND am.sin_lote = 1
            )
            ORDER BY me.id DESC
        ');

        foreach ($rows as $row) {
            $row->id = (int) $row->id;
            $row->id_empleado_registro = (int) $row->id_empleado_registro;
            $row->id_proveedor_minero = (int) $row->id_proveedor_minero;
            $row->numero_correlativo = (int) $row->numero_correlativo;

            $row->analisis = DB::select('
                SELECT
                    am.id,
                    am.id_grupo_analisis_detalle,
                    gad.id_grupo_analisis AS id_grupo_analisis,
                    gad.id_analito AS id_analito,
                    am.uuid_fila,
                    am.ley,
                    am.esta_confirmada,
                    am.tipo_origen,
                    am.log_cambios,
                    am.created_at
                FROM analisis_mineral am
                INNER JOIN grupo_analisis_detalle gad ON am.id_grupo_analisis_detalle = gad.id
                WHERE am.id_muestra_externa = :id
                ORDER BY am.id ASC
            ', ['id' => $row->id]);

            foreach ($row->analisis as $a) {
                $a->id = (int) $a->id;
                $a->id_grupo_analisis_detalle = (int) $a->id_grupo_analisis_detalle;
                $a->id_grupo_analisis = (int) $a->id_grupo_analisis;
                $a->id_analito = (int) $a->id_analito;
                $a->ley = (float) $a->ley;
                $a->esta_confirmada = (bool) $a->esta_confirmada;
                $a->log_cambios = isset($a->log_cambios) ? (is_array($a->log_cambios) ? $a->log_cambios : (json_decode($a->log_cambios, true) ?? [])) : [];
            }
        }

        return $rows;
    }

    /**
     * Listar las muestras externas que fueron asociadas a un lote específico
     * (sus analisis_mineral tienen id_lote_mineral = X y sin_lote = 0, id_muestra_externa IS NULL).
     */
    public static function get_muestras_asociadas_por_lote(int $idLoteMineral): array
    {
        $rows = DB::select('
            SELECT
                me.id,
                me.id_empleado_registro,
                me.id_proveedor_minero,
                me.correlativo,
                me.numero_correlativo,
                me.created_at,
                p.razon_social AS proveedor_razon_social,
                CONCAT(emp.nombre, " ", emp.apellido) AS empleado_registro_nombre
            FROM muestra_externa me
            INNER JOIN analisis_mineral am ON (
                am.id_muestra_externa = me.id
                OR (am.id_muestra_externa IS NULL AND am.log_cambios LIKE CONCAT("%", me.correlativo, "%"))
            )
            LEFT JOIN proveedor p ON me.id_proveedor_minero = p.id
            LEFT JOIN empleado emp ON me.id_empleado_registro = emp.id
            WHERE am.id_lote_mineral = :id_lote
              AND am.sin_lote = 0
            GROUP BY me.id
            ORDER BY me.id DESC
        ', ['id_lote' => $idLoteMineral]);

        foreach ($rows as $row) {
            $row->id = (int) $row->id;
            $row->id_empleado_registro = (int) $row->id_empleado_registro;
            $row->id_proveedor_minero = (int) $row->id_proveedor_minero;
            $row->numero_correlativo = (int) $row->numero_correlativo;

            // Curar registros previos donde id_muestra_externa quedó null
            AnalisisMineral::where('id_lote_mineral', $idLoteMineral)
                ->whereNull('id_muestra_externa')
                ->where('log_cambios', 'like', "%{$row->correlativo}%")
                ->update(['id_muestra_externa' => $row->id]);
        }

        return $rows;
    }

    /**
     * Crear un nuevo analisis_mineral asociado a una muestra externa.
     *
     * @param  array{id_muestra_externa: int, id_grupo_analisis_detalle: int, tipo_origen: string|null, uuid_fila: string, ley: float, esta_confirmada: int, id_empleado_registro: int}  $datos
     */
    public static function crear_analisis_mineral_muestra(array $datos): AnalisisMineral
    {
        $ley = isset($datos['ley']) ? (float) $datos['ley'] : 0.0;
        $estaConfirmada = isset($datos['esta_confirmada']) ? (bool) $datos['esta_confirmada'] : false;
        $tipoOrigen = $datos['tipo_origen'] ?? null;
        $idEmpleado = $datos['id_empleado_registro'] ?? 1;

        $cambios = [];

        if ($ley > 0) {
            $cambios[] = [
                'campo_bd' => 'ley',
                'campo' => 'Ley',
                'valor_anterior' => 0.0,
                'valor_nuevo' => $ley,
            ];
        }

        if ($estaConfirmada) {
            $cambios[] = [
                'campo_bd' => 'esta_confirmada',
                'campo' => 'Estado Confirmado',
                'valor_anterior' => false,
                'valor_nuevo' => true,
            ];
        }

        if ($tipoOrigen !== null) {
            $cambios[] = [
                'campo_bd' => 'tipo_origen',
                'campo' => 'Tipo de origen',
                'valor_anterior' => '—',
                'valor_nuevo' => $tipoOrigen,
            ];
        }

        if (! empty($cambios)) {
            $datos['log_cambios'] = [
                [
                    'id_empleado' => $idEmpleado,
                    'motivo' => null,
                    'update_at' => Carbon::now()->toDateTimeString(),
                    'cambios' => $cambios,
                ],
            ];
        }

        // Marca la fila como "sin lote" porque pertenece a una muestra externa.
        $datos['id_lote_mineral'] = null;
        $datos['sin_lote'] = 1;

        return AnalisisMineral::create($datos);
    }

    /**
     * Actualizar las filas no-desplegables de una muestra externa para un detalle específico
     * (mismo uuid_fila). Para muestras externas, cada corrida tiene su propio uuid_fila, así que
     * NUNCA actualizamos otras corridas — solo la indicada.
     */
    public static function actualizar_leyes_no_desplegables_muestra(
        int $idMuestraExterna,
        int $idGrupoAnalisisDetalle,
        float $ley,
        bool $estaConfirmada,
        int $idEmpleadoRegistro,
        string $uuidFila
    ): int {
        $registros = AnalisisMineral::where('id_muestra_externa', $idMuestraExterna)
            ->where('id_grupo_analisis_detalle', $idGrupoAnalisisDetalle)
            ->where('uuid_fila', $uuidFila)
            ->get();

        if ($registros->isEmpty()) {
            return 0;
        }

        foreach ($registros as $registro) {
            self::generar_log_cambio($registro, $ley, $estaConfirmada, null, $idEmpleadoRegistro);
            $registro->ley = $ley;
            $registro->esta_confirmada = $estaConfirmada ? 1 : 0;
            $registro->id_empleado_registro = $idEmpleadoRegistro;
            $registro->save();
        }

        return $registros->count();
    }

    /**
     * Eliminar todas las celdas de análisis de una corrida de una muestra externa.
     */
    public static function eliminar_fila_analisis_muestra(int $idMuestraExterna, string $uuidFila): void
    {
        AnalisisMineral::where('id_muestra_externa', $idMuestraExterna)
            ->where('uuid_fila', $uuidFila)
            ->delete();
    }

    /**
     * Contar cuántos análisis quedan en la muestra (sin importar estado).
     */
    public static function count_analisis_by_muestra(int $idMuestraExterna): int
    {
        return (int) DB::table('analisis_mineral')
            ->where('id_muestra_externa', $idMuestraExterna)
            ->count();
    }

    /**
     * Eliminar la cabecera de una muestra externa. Útil como cascade cuando ya no tiene análisis.
     */
    public static function eliminar_muestra_externa(int $idMuestra): void
    {
        DB::table('muestra_externa')->where('id', $idMuestra)->delete();
    }

    /**
     * Actualizar el tipo de origen de una corrida de análisis de una muestra externa.
     */
    public static function actualizar_origen_fila_muestra(int $idMuestraExterna, string $uuidFila, ?string $tipoOrigen, int $idEmpleado = 1): void
    {
        $registros = AnalisisMineral::where('id_muestra_externa', $idMuestraExterna)
            ->where('uuid_fila', $uuidFila)
            ->get();

        foreach ($registros as $registro) {
            self::generar_log_cambio($registro, null, null, $tipoOrigen, $idEmpleado);
            $registro->tipo_origen = $tipoOrigen;
            $registro->save();
        }
    }

    /**
     * Migrar los analisis_mineral de una muestra externa al lote destino:
     *  - id_lote_mineral = idLoteDestino
     *  - id_muestra_externa = NULL
     *  - sin_lote = 0
     *  - uuid_fila se conserva
     *  - Se agrega una entrada a log_cambios indicando la migración desde la muestra externa.
     *
     * @param  array  $muestra  Cabecera de muestra_externa con al menos 'correlativo'
     * @return int  Cantidad de analisis_mineral actualizados.
     */
    public static function asociar_analisis_muestra_a_lote(int $idMuestraExterna, int $idLoteDestino, int $idEmpleado, array $muestra): int
    {
        $registros = AnalisisMineral::where('id_muestra_externa', $idMuestraExterna)
            ->where('sin_lote', 1)
            ->get();

        foreach ($registros as $registro) {
            // Mantenemos id_muestra_externa (no la nuleamos) para preservar la trazabilidad
            // y permitir que get_muestras_asociadas_por_lote() siga relacionando las muestras
            // con sus análisis. La distinción "activa vs asociada" se hace por sin_lote.
            // NO se registra este cambio en log_cambios por requerimiento.
            $registro->id_lote_mineral = $idLoteDestino;
            $registro->sin_lote = 0;
            $registro->save();
        }

        return $registros->count();
    }
}
