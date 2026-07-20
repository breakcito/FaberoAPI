<?php

namespace App\Modules\CierreLeyes\Services;

use App\Models\AnalisisMineral;
use App\Models\GrupoAnalisisDetalle;
use App\Models\LoteMineral;
use App\Modules\CierreLeyes\Data\CierreLeyesData;
use App\Shared\Enums\_Generic\EstadoLeyes;
use App\Shared\Responses\ApiResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CierreLeyesService
{
    public static function get_lotes_sugeridos(): array
    {
        $data = CierreLeyesData::get_lotes_sugeridos();

        return ApiResponse::success($data, 'Lotes sugeridos obtenidos correctamente');
    }

    private static function crear_registros_vacios_analisis(int $idLote, string $uuidFila, int $idEmpleado): void
    {
        $detallesActivos = DB::table('grupo_analisis_detalle as gad')
            ->join('grupo_analisis as ga', 'gad.id_grupo_analisis', '=', 'ga.id')
            ->where('ga.estado', 'Activo')
            ->select('gad.id as detalle_id', 'ga.indicar_origen')
            ->get();

        foreach ($detallesActivos as $detalle) {
            // El tipo_origen siempre arranca null: el usuario debe elegirlo
            // explicitamente para grupos con indicar_origen=true.
            AnalisisMineral::create([
                'id_lote_mineral' => $idLote,
                'id_grupo_analisis_detalle' => $detalle->detalle_id,
                'tipo_origen' => null,
                'uuid_fila' => $uuidFila,
                'ley' => 0.0,
                'esta_confirmada' => 0, // Unchecked/Unconfirmed by default
                'id_empleado_registro' => $idEmpleado,
            ]);
        }
    }

    public static function iniciar_lote(int $idLote, int $idEmpleado): array
    {
        $lote = LoteMineral::find($idLote);
        if (! $lote) {
            return ApiResponse::error('Lote no encontrado');
        }

        if ($lote->estado_leyes !== 'Pendiente' && ! empty($lote->estado_leyes)) {
            return ApiResponse::error('El lote no se encuentra en estado Pendiente');
        }

        DB::beginTransaction();
        try {
            $lote->estado_leyes = EstadoLeyes::EnProceso->value;
            $lote->id_empleado_inicio_analisis = $idEmpleado;
            $lote->fecha_hora_inicio_analisis = Carbon::now();
            $lote->save();

            // Generar primer uuid_fila y crear los registros vacíos iniciales
            $uuidFila = \Illuminate\Support\Str::uuid()->toString();
            self::crear_registros_vacios_analisis($idLote, $uuidFila, $idEmpleado);

            DB::commit();

            $updatedLote = CierreLeyesData::get_lotes_cierre($idLote);
            $loteData = count($updatedLote) > 0 ? $updatedLote[0] : null;

            return ApiResponse::success($loteData, 'Análisis de leyes iniciado correctamente para el lote');
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al iniciar el lote: '.$e->getMessage());
        }
    }

    public static function agregar_analisis(int $idLote, int $idEmpleado): array
    {
        $lote = LoteMineral::find($idLote);
        if (! $lote) {
            return ApiResponse::error('Lote no encontrado');
        }

        if ($lote->estado_leyes !== 'En Proceso') {
            return ApiResponse::error('El lote no se encuentra en proceso de análisis');
        }

        DB::beginTransaction();
        try {
            // Generar nuevo uuid_fila y crear los registros vacíos
            $uuidFila = \Illuminate\Support\Str::uuid()->toString();
            self::crear_registros_vacios_analisis($idLote, $uuidFila, $idEmpleado);

            DB::commit();

            $updatedLote = CierreLeyesData::get_lotes_cierre($idLote);
            $loteData = count($updatedLote) > 0 ? $updatedLote[0] : null;

            return ApiResponse::success($loteData, 'Nuevo análisis agregado correctamente');
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al agregar análisis: '.$e->getMessage());
        }
    }

    public static function get_lotes_cierre(?string $estado = null, ?string $fechaInicio = null, ?string $fechaFin = null, ?int $id = null): array
    {
        $filtros = [];

        if ($id !== null) {
            $filtros['id_lookup'] = $id;
        }

        // Estados: si llega uno solo, lo usamos; si llega "Todos", incluimos ambos.
        if ($estado !== null && $estado !== '' && $estado !== 'Todos') {
            $filtros['estados'] = [$estado];
        }

        if ($fechaInicio !== null && $fechaInicio !== '') {
            $filtros['fecha_inicio'] = $fechaInicio;
        }

        if ($fechaFin !== null && $fechaFin !== '') {
            $filtros['fecha_fin'] = $fechaFin;
        }

        // Para mantener compatibilidad con el helper que acepta ?int $id,
        // separamos ese parametro del array de filtros.
        $idLookup = $filtros['id_lookup'] ?? null;
        unset($filtros['id_lookup']);

        $data = CierreLeyesData::get_lotes_cierre($idLookup, $filtros);

        return ApiResponse::success($data, 'Lotes en proceso/confirmados obtenidos correctamente');
    }

    public static function guardar_valor_ley(
        int $idLoteMineral,
        int $idGrupoAnalisisDetalle,
        ?string $tipoOrigen,
        string $uuidFila,
        float $ley,
        bool $estaConfirmada,
        int $idEmpleadoRegistro,
        ?int $id = null
    ): array {
        if ($estaConfirmada && $ley <= 0) {
            return ApiResponse::error('No se puede confirmar un análisis sin un valor mayor a cero.');
        }

        DB::beginTransaction();
        try {
            // Verificar si el detalle y analito es desplegable
            $detalle = GrupoAnalisisDetalle::with('analito')->find($idGrupoAnalisisDetalle);
            if (! $detalle) {
                return ApiResponse::error('El detalle del grupo de análisis no existe');
            }

            $esDesplegable = $detalle->analito ? (bool) $detalle->analito->es_desplegable : false;

            if (! $esDesplegable) {
                // Si no es desplegable, actualizamos todos los registros de este detalle en el lote
                // (incluyendo cualquier origen o fila de corrida)
                $affected = AnalisisMineral::where('id_lote_mineral', $idLoteMineral)
                    ->where('id_grupo_analisis_detalle', $idGrupoAnalisisDetalle)
                    ->update([
                        'ley' => $ley,
                        'esta_confirmada' => $estaConfirmada ? 1 : 0,
                        'id_empleado_registro' => $idEmpleadoRegistro,
                    ]);

                if ($affected === 0) {
                    AnalisisMineral::create([
                        'id_lote_mineral' => $idLoteMineral,
                        'id_grupo_analisis_detalle' => $idGrupoAnalisisDetalle,
                        'tipo_origen' => $tipoOrigen,
                        'uuid_fila' => $uuidFila,
                        'ley' => $ley,
                        'esta_confirmada' => $estaConfirmada ? 1 : 0,
                        'id_empleado_registro' => $idEmpleadoRegistro,
                    ]);
                }
            } else {
                if ($id !== null) {
                    $registro = AnalisisMineral::find($id);
                    if (! $registro) {
                        return ApiResponse::error('Registro de análisis no encontrado para actualizar');
                    }
                    $registro->update([
                        'ley' => $ley,
                        'esta_confirmada' => $estaConfirmada ? 1 : 0,
                        'id_empleado_registro' => $idEmpleadoRegistro,
                    ]);
                } else {
                    AnalisisMineral::create([
                        'id_lote_mineral' => $idLoteMineral,
                        'id_grupo_analisis_detalle' => $idGrupoAnalisisDetalle,
                        'tipo_origen' => $tipoOrigen,
                        'uuid_fila' => $uuidFila,
                        'ley' => $ley,
                        'esta_confirmada' => $estaConfirmada ? 1 : 0,
                        'id_empleado_registro' => $idEmpleadoRegistro,
                    ]);
                }
            }

            DB::commit();

            // Retornar los lotes actualizados para sincronizar el frontend
            $updatedLote = CierreLeyesData::get_lotes_cierre($idLoteMineral);
            $loteData = count($updatedLote) > 0 ? $updatedLote[0] : null;

            return ApiResponse::success($loteData, 'Valor de ley guardado correctamente');
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al guardar el valor de ley: '.$e->getMessage());
        }
    }

    public static function eliminar_valor(int $id): array
    {
        $registro = AnalisisMineral::find($id);
        if (! $registro) {
            return ApiResponse::error('Registro de análisis no encontrado');
        }

        $idLoteMineral = $registro->id_lote_mineral;
        $registro->delete();

        // Retornar el lote actualizado
        $updatedLote = CierreLeyesData::get_lotes_cierre($idLoteMineral);
        $loteData = count($updatedLote) > 0 ? $updatedLote[0] : null;

        return ApiResponse::success($loteData, 'Registro de análisis eliminado correctamente');
    }

    public static function eliminar_fila(int $idLoteMineral, string $uuidFila): array
    {
        AnalisisMineral::where('id_lote_mineral', $idLoteMineral)
            ->where('uuid_fila', $uuidFila)
            ->delete();

        // Retornar el lote actualizado
        $updatedLote = CierreLeyesData::get_lotes_cierre($idLoteMineral);
        $loteData = count($updatedLote) > 0 ? $updatedLote[0] : null;

        return ApiResponse::success($loteData, 'Fila de análisis eliminada correctamente');
    }

    /**
     * Valida que el lote pueda cerrarse:
     *  - Todos los analisis_mineral del lote deben estar confirmados (esta_confirmada = 1).
     *  - Ningún analisis_mineral del lote puede tener ley nula.
     *
     * @return array{ok: bool, motivo?: string}
     */
    private static function validar_cierre(int $idLote): array
    {
        $filas = AnalisisMineral::where('id_lote_mineral', $idLote)->get();

        if ($filas->contains(fn ($f) => ! (bool) $f->esta_confirmada)) {
            return [
                'ok' => false,
                'motivo' => 'Hay análisis sin confirmar. Marca todas las casillas antes de cerrar el lote.',
            ];
        }

        if ($filas->contains(fn ($f) => $f->ley === null || (float) $f->ley <= 0)) {
            return [
                'ok' => false,
                'motivo' => 'Hay análisis con valor nulo o igual a cero. Completa todos los valores antes de cerrar el lote.',
            ];
        }

        return ['ok' => true];
    }

    /**
     * Consolida las leyes del lote a partir de los analisis confirmados.
     *
     * Regla de consolidacion (cierre-leyes):
     *   1. Se agrupan los analisis_mineral confirmados (esta_confirmada = 1) del lote
     *      por id_grupo_analisis_detalle.
     *   2. Para cada grupo se calcula el VALOR REPRESENTATIVO del analito, gate
     *      explicito por analito.es_desplegable:
     *      a. es_desplegable = true  -> promedio aritmetico de todas las corridas
     *         confirmadas del analito.
     *      b. es_desplegable = false -> se toma el valor de la primera fila (el
     *         frontend solo permite un valor unico replicado en todas las filas
     *         via `guardar_valor_ley`; no se promedia).
     *   3. El valor se asigna a lote_mineral.ley_<x> segun la flag
     *      'para_valorizacion_<x>' del grupo_analisis_detalle (single-select: solo
     *      una flag activa por detalle).
     *   4. Si el detalle no tiene flag activa, el valor se descarta pero los
     *      registros siguen disponibles en analisis_mineral (trazabilidad).
     *
     * @return array{ley_oro: float, ley_plata: float, ley_humedad: float, ley_recuperacion: float}
     */
    private static function consolidar_leyes(int $idLote): array
    {
        $analisisConfirmados = AnalisisMineral::where('id_lote_mineral', $idLote)
            ->where('esta_confirmada', 1)
            ->get();

        $leyesValores = [
            'ley_oro' => 0.0,
            'ley_plata' => 0.0,
            'ley_humedad' => 0.0,
            'ley_recuperacion' => 0.0,
        ];

        $agrupados = $analisisConfirmados->groupBy('id_grupo_analisis_detalle');

        foreach ($agrupados as $idDetalle => $registros) {
            $detalle = GrupoAnalisisDetalle::with('analito')->find($idDetalle);
            if (! $detalle) {
                continue;
            }

            $esDesplegable = $detalle->analito ? (bool) $detalle->analito->es_desplegable : false;

            if ($esDesplegable) {
                // Regla: promedio aritmetico entre todas las corridas confirmadas del analito.
                $sumaLeyes = 0.0;
                $cant = 0;
                foreach ($registros as $reg) {
                    $sumaLeyes += (float) $reg->ley;
                    $cant++;
                }
                $valor = $cant > 0 ? $sumaLeyes / $cant : 0.0;
            } else {
                // No desplegable: el frontend muestra una sola celda y `guardar_valor_ley`
                // replica el valor unico en todas las filas del detalle. Se toma el
                // primer registro sin promediar.
                $valor = (float) ($registros[0]->ley ?? 0.0);
            }

            if ((bool) $detalle->para_valorizacion_oro) {
                $leyesValores['ley_oro'] = $valor;
            }
            if ((bool) $detalle->para_valorizacion_plata) {
                $leyesValores['ley_plata'] = $valor;
            }
            if ((bool) $detalle->para_valorizacion_humedad) {
                $leyesValores['ley_humedad'] = $valor;
            }
            if ((bool) $detalle->para_valorizacion_recuperacion) {
                $leyesValores['ley_recuperacion'] = $valor;
            }
        }

        return $leyesValores;
    }

    public static function confirmar_lote_leyes(int $idLote, bool $conValorComercial, int $idEmpleado): array
    {
        $lote = LoteMineral::find($idLote);
        if (! $lote) {
            return ApiResponse::error('Lote no encontrado');
        }

        if ($lote->estado_leyes !== 'En Proceso') {
            return ApiResponse::error('El lote no se encuentra en proceso de análisis');
        }

        $validacion = self::validar_cierre($idLote);
        if (! $validacion['ok']) {
            return ApiResponse::error($validacion['motivo']);
        }

        DB::beginTransaction();
        try {
            $leyesValores = self::consolidar_leyes($idLote);

            $lote->ley_oro = $leyesValores['ley_oro'];
            $lote->ley_plata = $leyesValores['ley_plata'];
            $lote->ley_humedad = $leyesValores['ley_humedad'];
            $lote->ley_recuperacion = $leyesValores['ley_recuperacion'];

            $lote->estado_leyes = EstadoLeyes::Confirmado->value;
            $lote->con_valor_comercial = $conValorComercial ? 1 : 0;
            $lote->id_empleado_confirmacion_analisis = $idEmpleado;
            $lote->fecha_hora_confirmacion_analisis = Carbon::now();
            $lote->save();

            DB::commit();

            $updatedLote = CierreLeyesData::get_lotes_cierre($idLote);
            $loteData = count($updatedLote) > 0 ? $updatedLote[0] : null;

            return ApiResponse::success($loteData, 'Lote de leyes confirmado y cerrado correctamente');
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al confirmar el cierre de leyes: '.$e->getMessage());
        }
    }

    public static function actualizar_origen_fila(int $idLoteMineral, string $uuidFila, ?string $tipoOrigen): array
    {
        AnalisisMineral::where('id_lote_mineral', $idLoteMineral)
            ->where('uuid_fila', $uuidFila)
            ->update(['tipo_origen' => $tipoOrigen]);

        $updatedLote = CierreLeyesData::get_lotes_cierre($idLoteMineral);
        $loteData = count($updatedLote) > 0 ? $updatedLote[0] : null;

        return ApiResponse::success($loteData, 'Origen de la corrida actualizado correctamente');
    }
}
