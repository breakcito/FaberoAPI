<?php

namespace App\Modules\ProgramacionDespachos\Data;

use Illuminate\Support\Facades\DB;

class ActaSalidaVehiculoData
{
    /**
     * Construye el shape completo del acta de salida a partir de la distribución.
     * Hace JOINs a despacho, empresa, planta_destino, vehiculo (y carreta),
     * empresa_transporte, conductor, lote_mineral, recepcion_unidad y guia_segundo_tramo.
     *
     * @return array<string, mixed>|null  null si no existe la distribución
     */
    public static function get_por_distribucion(int $idDistribucion): ?array
    {
        $sql = <<<'SQL'
            SELECT
                d.id                                          AS distribucion_id,
                desp.correlativo                              AS correlativo,
                desp.numero_correlativo                       AS numero_correlativo,
                emp_fabero.razon_social                       AS empresa_razon_social,
                emp_fabero.ruc                                AS empresa_ruc,
                emp_fabero.domicilio_fiscal                   AS empresa_domicilio_fiscal,
                s_dir.direccion                               AS empresa_sede_direccion,
                s_dir.departamento_nombre                     AS empresa_sede_departamento,
                s_dir.provincia_nombre                        AS empresa_sede_provincia,
                s_dir.distrito_nombre                         AS empresa_sede_distrito,
                COALESCE(pd_remitente.razon_social, emp_remitente.razon_social, pd.razon_social, emp_fabero.razon_social) AS remitente_razon_social,
                COALESCE(pd_remitente.ruc, emp_remitente.ruc, pd.ruc, emp_fabero.ruc) AS remitente_ruc,
                COALESCE(s_dir.direccion, emp_fabero.domicilio_fiscal) AS direccion_partida,
                COALESCE(pd.direccion, '—')                   AS direccion_destino,
                prov.razon_social                             AS proveedor_razon_social,
                prov.ruc                                      AS proveedor_ruc,
                prov.direccion                                AS proveedor_direccion,
                pd.razon_social                               AS planta_destino_razon_social,
                pd.ruc                                        AS planta_destino_ruc,
                pd.direccion                                  AS planta_destino_direccion,
                v.placa                                       AS vehiculo_placa,
                mv.nombre                                     AS vehiculo_marca,
                vc.placa                                      AS carreta_placa,
                mc.nombre                                     AS carreta_marca,
                et.razon_social                               AS transportista_razon_social,
                et.ruc                                        AS transportista_ruc,
                et.direccion                                  AS transportista_direccion,
                CONCAT(c.nombre, ' ', c.apellido)             AS conductor_nombre_completo,
                c.numero_licencia                             AS conductor_licencia,
                gst.guia_remitente                            AS guia_remitente,
                gst.guia_transportista                        AS guia_transportista,
                ru.fecha_hora_ingreso                         AS fecha_hora_ingreso,
                ru.fecha_hora_salida                          AS fecha_hora_salida,
                lm.correlativo                                AS cod_lote,
                COALESCE(lm.tipo_producto, 'MINERAL AURIFERO EN BRUTO SIN PROCESAR') AS producto,
                dd_sum.total_peso_neto_kg                     AS total_peso_neto_kg
            FROM distribucion d
            INNER JOIN despacho desp                  ON desp.id = d.id_despacho
            LEFT  JOIN empresa emp_fabero             ON emp_fabero.id = desp.id_empresa
            LEFT  JOIN planta_destino pd              ON pd.id = desp.id_planta_destino
            INNER JOIN vehiculo v                    ON v.id = d.id_vehiculo
            LEFT  JOIN marca mv                       ON mv.id = v.id_marca
            LEFT  JOIN vehiculo vc                   ON vc.id = d.id_vehiculo_carreta
            LEFT  JOIN marca mc                       ON mc.id = vc.id_marca
            INNER JOIN empresa_transporte et          ON et.id = d.id_empresa_transporte
            LEFT JOIN distribucion_detalle dd        ON dd.id_distribucion = d.id
            LEFT JOIN despacho_detalle desdd        ON desdd.id = dd.id_despacho_detalle
            LEFT JOIN lote_mineral lm               ON lm.id = desdd.id_lote_mineral
            LEFT JOIN proveedor prov                ON prov.id = lm.id_proveedor_minero
            LEFT JOIN guia_segundo_tramo gst        ON gst.id_ditribucion = d.id AND gst.estado = 'Activo'
            LEFT JOIN empresa emp_remitente           ON emp_remitente.id = gst.id_empresa
            LEFT JOIN planta_destino pd_remitente     ON pd_remitente.id = gst.id_planta_destino
            LEFT JOIN recepcion_unidad ru           ON ru.id_distribucion = d.id
            LEFT JOIN conductor c                    ON c.id = ru.id_conductor
            LEFT JOIN (
                SELECT
                    id_distribucion,
                    SUM(peso_neto)                AS total_peso_neto_kg
                FROM distribucion_detalle
                WHERE peso_neto IS NOT NULL
                GROUP BY id_distribucion
            ) dd_sum ON dd_sum.id_distribucion = d.id
            LEFT  JOIN (
                SELECT
                    s.id                          AS id_sucursal,
                    d2.nombre                     AS departamento_nombre,
                    p2.nombre                     AS provincia_nombre,
                    di.nombre                     AS distrito_nombre,
                    CONCAT_WS(' - ',
                        s.direccion,
                        d2.nombre,
                        p2.nombre,
                        di.nombre
                    )                            AS direccion
                FROM sucursal s
                LEFT JOIN departamento d2 ON d2.id = s.id_departamento
                LEFT JOIN provincia p2  ON p2.id  = s.id_provincia
                LEFT JOIN distrito di   ON di.id  = s.id_distrito
            ) s_dir ON s_dir.id_sucursal = d.id_sucursal
            WHERE d.id = :id
            LIMIT 1
        SQL;

        $row = DB::selectOne($sql, ['id' => $idDistribucion]);
        if (! $row) {
            return null;
        }

        return self::hydrate($row);
    }

    /**
     * Convierte el row crudo a la forma lista para el PDF.
     * `null` → `'—'` en strings, `null` → `0` en numéricos que se imprimen como TM.
     */
    private static function hydrate(object $row): array
    {
        $sedePartes = array_filter([
            $row->empresa_sede_direccion ?? null,
            $row->empresa_sede_departamento ?? null,
            $row->empresa_sede_provincia ?? null,
            $row->empresa_sede_distrito ?? null,
        ], fn ($v) => is_string($v) && trim($v) !== '');

        $empresaSedeProductiva = $sedePartes === []
            ? '—'
            : implode(' - ', $sedePartes);

        $partida = ! empty($row->direccion_partida) ? $row->direccion_partida : $empresaSedeProductiva;
        $destino = ! empty($row->direccion_destino) ? $row->direccion_destino : ($row->planta_destino_direccion ?? '—');

        // Formato fechas y horas
        $fechaHoraIngreso = ! empty($row->fecha_hora_ingreso) ? \Carbon\Carbon::parse($row->fecha_hora_ingreso) : null;
        $fechaHoraSalida = ! empty($row->fecha_hora_salida) ? \Carbon\Carbon::parse($row->fecha_hora_salida) : null;

        $fechaIngreso = $fechaHoraIngreso ? $fechaHoraIngreso->format('d/m/Y') : '—';
        $horaIngreso = $fechaHoraIngreso ? $fechaHoraIngreso->format('h:i:s a') : '—';
        $fechaSalida = $fechaHoraSalida ? $fechaHoraSalida->format('d/m/Y') : '—';
        $horaSalida = $fechaHoraSalida ? $fechaHoraSalida->format('h:i:s a') : '—';

        // Peso en TM (toneladas métricas). Fuente única: SUM de los detalles de la
        // distribución (`distribucion_detalle.peso_neto`). Sirve para AMBOS campos
        // del PDF: "Peso de Guía de Remisión" y "Peso Vehicular Total".
        $pesoKg = (float) ($row->total_peso_neto_kg ?? 0);
        $pesoVehicularTotalTm = $pesoKg > 0 ? round($pesoKg / 1000, 3) : 0;
        $pesoGuiaTm = $pesoVehicularTotalTm;

        $remitenteRazonSocial = self::strOrDash($row->remitente_razon_social ?? $row->empresa_razon_social ?? null);
        $remitenteRuc = self::strOrDash($row->remitente_ruc ?? $row->empresa_ruc ?? null);

        return [
            'correlativo' => self::strOrDash($row->correlativo ?? null),
            'tsv' => self::strOrDash($row->correlativo ?? null),
            'numero_correlativo' => $row->numero_correlativo !== null ? (int) $row->numero_correlativo : null,

            'empresa_remitente' => [
                'razon_social' => self::strOrDash($row->empresa_razon_social ?? null),
                'ruc' => self::strOrDash($row->empresa_ruc ?? null),
                'domicilio_fiscal' => self::strOrDash($row->empresa_domicilio_fiscal ?? null),
                'sede_productiva' => $empresaSedeProductiva,
            ],

            'remitente' => [
                'razon_social' => $remitenteRazonSocial,
                'ruc' => $remitenteRuc,
                'direccion_partida' => self::strOrDash($partida),
                'direccion_destino' => self::strOrDash($destino),
            ],

            'proveedor' => [
                'razon_social' => $remitenteRazonSocial,
                'ruc' => $remitenteRuc,
                'direccion_partida' => self::strOrDash($partida),
            ],

            'destino' => [
                'razon_social' => self::strOrDash($row->planta_destino_razon_social ?? null),
                'ruc' => self::strOrDash($row->planta_destino_ruc ?? null),
                'direccion' => self::strOrDash($destino),
            ],

            'vehiculo' => [
                'placa' => self::strOrDash($row->vehiculo_placa ?? null),
                'marca_trabajo' => self::strOrDash($row->vehiculo_marca ?? null),
                'configuracion_vehicular' => '—',
            ],

            'carreta' => $row->carreta_placa
                ? [
                    'placa' => self::strOrDash($row->carreta_placa ?? null),
                    'marca_trabajo' => self::strOrDash($row->carreta_marca ?? null),
                ]
                : null,

            'transportista' => [
                'razon_social' => self::strOrDash($row->transportista_razon_social ?? null),
                'ruc' => self::strOrDash($row->transportista_ruc ?? null),
                'plataforma_contratista' => '—',
            ],

            'conductor' => [
                'nombre_completo' => self::strOrDash($row->conductor_nombre_completo ?? null),
                'licencia' => self::strOrDash($row->conductor_licencia ?? null),
            ],

            'guia_remitente' => self::strOrDash($row->guia_remitente ?? null),
            'producto' => self::strOrDash($row->producto ?? null),
            'guia_transportista' => self::strOrDash($row->guia_transportista ?? null),

            'fecha_ingreso' => $fechaIngreso,
            'hora_ingreso' => $horaIngreso,
            'fecha_salida' => $fechaSalida,
            'hora_salida' => $horaSalida,

            'peso_guia_tm' => $pesoGuiaTm,
            'peso_vehicular_total_tm' => $pesoVehicularTotalTm,
            'cod_lote' => self::strOrDash($row->cod_lote ?? null),
            'observaciones' => '—',
        ];
    }

    private static function strOrDash(?string $v): string
    {
        if ($v === null) {
            return '—';
        }
        $trimmed = trim($v);
        return $trimmed === '' ? '—' : $trimmed;
    }

    private static function formatDateTime(mixed $v): string
    {
        if ($v === null) {
            return '—';
        }
        $s = is_string($v) ? trim($v) : '';
        if ($s === '') {
            return '—';
        }
        // Normalizar separador espacio → T para Date de JS, pero como string lo dejamos legible.
        return $s;
    }
}
