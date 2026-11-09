<?php

namespace App\Modules\ContabilidadCompra\Data;

use App\Models\ComprobanteNota;
use App\Shared\Enums\ContabilidadCompra\MotivoNotaCreditoComprobante;
use App\Shared\Enums\ContabilidadCompra\MotivoNotaDebitoComprobante;
use Illuminate\Support\Facades\DB;

class ComprobanteNotaData
{
    /**
     * Listar las notas de un comprobante (vigentes primero, luego anuladas).
     *
     * @return array<int,object>
     */
    public static function get_notas_by_comprobante(int $idComprobante): array
    {
        return self::get_notas_por_comprobantes([$idComprobante])[$idComprobante] ?? [];
    }

    /**
     * Listar las notas de varios comprobantes en una sola consulta.
     *
     * @param  array<int,int>  $idsComprobantes
     * @return array<int,array<int,object>> índice por id de comprobante
     */
    public static function get_notas_por_comprobantes(array $idsComprobantes): array
    {
        $idsComprobantes = array_values(array_unique(array_map('intval', $idsComprobantes)));
        if (empty($idsComprobantes)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($idsComprobantes), '?'));

        $sql = "
            SELECT
                cn.id,
                cn.id_comprobante_compra,
                cn.id_empleado_registro,
                cn.id_empleado_anulacion,
                cn.tipo,
                cn.fecha_emision,
                cn.codigo_nota,
                cn.motivo,
                cn.monto,
                cn.documentos,
                cn.es_anulado,
                cn.fecha_hora_anulacion,
                cn.motivo_anulacion,
                cn.created_at,
                CONCAT(emp_reg.nombre, ' ', emp_reg.apellido) AS empleado_registro_nombre,
                CONCAT(emp_anu.nombre, ' ', emp_anu.apellido) AS empleado_anulacion_nombre
            FROM comprobante_nota cn
            LEFT JOIN empleado emp_reg ON emp_reg.id = cn.id_empleado_registro
            LEFT JOIN empleado emp_anu ON emp_anu.id = cn.id_empleado_anulacion
            WHERE cn.id_comprobante_compra IN ({$placeholders})
            ORDER BY cn.es_anulado ASC, cn.fecha_emision DESC, cn.id DESC
        ";

        $rows = DB::select($sql, $idsComprobantes);

        $porComprobante = [];
        foreach ($rows as $r) {
            $r->id = (int) $r->id;
            $r->id_comprobante_compra = (int) $r->id_comprobante_compra;
            $r->id_empleado_registro = $r->id_empleado_registro !== null ? (int) $r->id_empleado_registro : null;
            $r->id_empleado_anulacion = $r->id_empleado_anulacion !== null ? (int) $r->id_empleado_anulacion : null;
            $r->monto = (float) ($r->monto ?? 0);
            $r->es_anulado = (bool) ($r->es_anulado ?? false);
            $r->documentos = self::parse_json_array($r->documentos ?? null);
            $r->motivo_descripcion = self::resolver_descripcion_motivo((string) $r->tipo, (string) ($r->motivo ?? ''));

            $porComprobante[$r->id_comprobante_compra][] = $r;
        }

        return $porComprobante;
    }

    /**
     * Resolver la descripción oficial del motivo según el tipo de nota.
     * Cada tipo tiene su propio catálogo SUNAT, por eso se consulta por separado.
     */
    public static function resolver_descripcion_motivo(string $tipo, string $codigo): ?string
    {
        if ($codigo === '') {
            return null;
        }

        if ($tipo === 'Debito') {
            return MotivoNotaDebitoComprobante::tryFrom($codigo)?->descripcion();
        }

        return MotivoNotaCreditoComprobante::tryFrom($codigo)?->descripcion();
    }

    /**
     * Verificar si el código de nota ya existe en el comprobante.
     */
    public static function existe_codigo_nota(int $idComprobante, string $codigo, ?int $excluirId = null): bool
    {
        $query = DB::table('comprobante_nota')
            ->where('id_comprobante_compra', $idComprobante)
            ->where('codigo_nota', $codigo);

        if ($excluirId !== null) {
            $query->where('id', '!=', $excluirId);
        }

        return $query->exists();
    }

    /**
     * Verificar si el comprobante tiene una nota vigente del tipo indicado.
     *
     * @param  string  $tipo  valor del enum TipoNotaComprobante
     */
    public static function existe_nota_vigente_de_tipo(int $idComprobante, string $tipo): bool
    {
        return DB::table('comprobante_nota')
            ->where('id_comprobante_compra', $idComprobante)
            ->where('es_anulado', 0)
            ->where('tipo', $tipo)
            ->exists();
    }

    /**
     * Insertar una nota y devolver su ID.
     *
     * @param  array<string,mixed>  $campos
     */
    public static function insertar_nota(array $campos): int
    {
        return ComprobanteNota::insertGetId($campos + [
            'es_anulado' => false,
            'id_empleado_anulacion' => null,
            'fecha_hora_anulacion' => null,
            'motivo_anulacion' => null,
            'created_at' => now(),
        ]);
    }

    /**
     * Anular lógicamente una nota.
     */
    public static function anular_nota(int $idNota, int $idEmpleadoAnulacion, string $motivoAnulacion): bool
    {
        return DB::table('comprobante_nota')
            ->where('id', $idNota)
            ->where('es_anulado', 0)
            ->update([
                'es_anulado' => true,
                'id_empleado_anulacion' => $idEmpleadoAnulacion ?: null,
                'fecha_hora_anulacion' => now(),
                'motivo_anulacion' => $motivoAnulacion,
            ]) >= 0;
    }

    /**
     * Anular en cascada todas las notas vigentes de un comprobante.
     * Se ejecuta al anular el comprobante: las notas quedan anuladas con el
     * mismo motivo y responsable.
     *
     * @return int cantidad de notas anuladas
     */
    public static function anular_notas_del_comprobante(int $idComprobante, int $idEmpleadoAnulacion, string $motivoAnulacion): int
    {
        return DB::table('comprobante_nota')
            ->where('id_comprobante_compra', $idComprobante)
            ->where('es_anulado', 0)
            ->update([
                'es_anulado' => true,
                'id_empleado_anulacion' => $idEmpleadoAnulacion ?: null,
                'fecha_hora_anulacion' => now(),
                'motivo_anulacion' => $motivoAnulacion,
            ]);
    }

    /**
     * Suma firmada (en USD) del efecto de las notas vigentes sobre el monto neto.
     * Crédito resta, débito suma.
     */
    public static function sumar_delta_neto(int $idComprobante): float
    {
        $row = DB::selectOne(
            "SELECT COALESCE(SUM(CASE WHEN tipo = 'Credito' THEN -monto ELSE monto END), 0) AS delta
             FROM comprobante_nota
             WHERE id_comprobante_compra = :id AND es_anulado = 0",
            ['id' => $idComprobante]
        );

        return (float) ($row->delta ?? 0);
    }

    /**
     * Contar notas vigentes (no anuladas) de un comprobante.
     */
    public static function contar_notas_vigentes(int $idComprobante): int
    {
        return (int) DB::table('comprobante_nota')
            ->where('id_comprobante_compra', $idComprobante)
            ->where('es_anulado', 0)
            ->count();
    }

    /**
     * Recalcular y persistir el snapshot de montos ajustados del comprobante.
     *
     * - monto_neto_final (USD) = monto_neto + delta de las notas vigentes
     * - monto_detraccion_soles_final (PEN) = monto_detraccion_soles
     *     proporcional al nuevo neto (la detracción es un % del total, por eso
     *     el ratio preserva exactamente la base de cálculo).
     *
     * Los montos reales (`total`, `monto_neto`, `monto_detraccion_soles`) NO se tocan.
     *
     * @return array{monto_neto_final: float, monto_detraccion_soles_final: float}
     */
    public static function recalcular_montos_finales(int $idComprobante): array
    {
        $comprobante = DB::table('comprobante_compra')->where('id', $idComprobante)->first();
        if (! $comprobante) {
            return ['monto_neto_final' => 0.0, 'monto_detraccion_soles_final' => 0.0];
        }

        $montoNeto = (float) $comprobante->monto_neto;
        $montoDetraccionSoles = (float) $comprobante->monto_detraccion_soles;
        $delta = self::sumar_delta_neto($idComprobante);

        $montoNetoFinal = max(0.0, round($montoNeto + $delta, 2));
        $montoDetraccionSolesFinal = $montoNeto > 0
            ? max(0.0, round($montoDetraccionSoles * ($montoNetoFinal / $montoNeto), 2))
            : 0.0;

        DB::table('comprobante_compra')
            ->where('id', $idComprobante)
            ->update([
                'monto_neto_final' => $montoNetoFinal,
                'monto_detraccion_soles_final' => $montoDetraccionSolesFinal,
            ]);

        return [
            'monto_neto_final' => $montoNetoFinal,
            'monto_detraccion_soles_final' => $montoDetraccionSolesFinal,
        ];
    }

    /**
     * Marcar la valorización como "dirimencia" y asignarle su correlativo propio.
     *
     * El UPDATE es condicional (`con_dirimencia = 0`): solo la PRIMERA nota de
     * un comprobante asigna el correlativo. Las siguientes conservan el
     * correlativo ya emitido (un documento emitido no se destroys).
     *
     * IMPORTANTE: este UPDATE no escribe en `log_cambios` — el cambio de
     * correlativo/título de dirimencia no debe aparecer en el historial de
     * auditoría de la valorización.
     */
    public static function marcar_dirimencia_valorizacion(int $idValorizacion, string $correlativo, int $numero): bool
    {
        return DB::table('valorizacion_compra')
            ->where('id', $idValorizacion)
            ->where('con_dirimencia', 0)
            ->update([
                'con_dirimencia' => 1,
                'correlativo_dirimencia' => $correlativo,
                'numero_correlativo_dirimencia' => $numero,
                'fecha_hora_dirimencia' => now(),
            ]) === 1;
    }

    /**
     * Obtener la valorización asociada a un comprobante (solo lectura).
     */
    public static function get_valorizacion_de_comprobante(int $idComprobante): ?object
    {
        return DB::table('comprobante_compra')
            ->join('valorizacion_compra', 'valorizacion_compra.id', '=', 'comprobante_compra.id_valorizacion_compra')
            ->where('comprobante_compra.id', $idComprobante)
            ->select([
                'valorizacion_compra.id',
                'valorizacion_compra.correlativo',
                'valorizacion_compra.numero_correlativo',
                'valorizacion_compra.con_dirimencia',
                'valorizacion_compra.correlativo_dirimencia',
                'valorizacion_compra.numero_correlativo_dirimencia',
            ])
            ->first();
    }

    /**
     * Parsea un valor JSON a array, manejando cadenas simples o doblemente codificadas.
     *
     * @return array<int, mixed>
     */
    private static function parse_json_array(mixed $val): array
    {
        if (is_array($val)) {
            return $val;
        }
        if (! is_string($val) || trim($val) === '') {
            return [];
        }
        $decoded = json_decode($val, true);
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
