<?php

namespace App\Modules\GuiasPrimerTramo\Services;

use App\Modules\GuiasPrimerTramo\Data\GuiasPrimerTramoData;
use App\Shared\Enums\_Generic\Periodo;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Helpers\CorrelativoHelper;
use App\Shared\Responses\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GuiasPrimerTramoService
{
    /**
     * Listar guías filtradas por sucursal.
     */
    public static function get_guias(array $filters): array
    {
        if (empty($filters['id_sucursal'])) {
            return ApiResponse::error('Debe seleccionar una sucursal.');
        }

        $data = GuiasPrimerTramoData::get_guias($filters);

        return ApiResponse::success($data, 'Guías de primer tramo obtenidas correctamente.');
    }

    /**
     * Obtener metadatos para los filtros.
     */
    public static function get_filtros_metadata(int $idSucursal): array
    {
        $data = GuiasPrimerTramoData::get_filtros_metadata($idSucursal);

        return ApiResponse::success($data, 'Metadatos de filtros obtenidos correctamente.');
    }

    /**
     * Obtener una guía por id.
     */
    public static function get_guia_by_id(int $id): array
    {
        $guia = GuiasPrimerTramoData::get_guia_by_id($id);
        if (! $guia) {
            return ApiResponse::error('No se encontró la guía de primer tramo.');
        }

        return ApiResponse::success($guia, 'Guía de primer tramo obtenida correctamente.');
    }

    /**
     * Crear una nueva guía de primer tramo con sus lotes.
     *
     * $lotes es un array de objetos con keys: id_lote_mineral, peso_bruto, tara (los pesos
     * pueden ser ajustados en el frontend). El orden de inserción define el correlativo.
     *
     * $archivos son los archivos físicos de evidencias a guardar.
     */
    public static function crear_guia(array $data, array $lotes, array $archivos): array
    {
        // 1. Validaciones mínimas
        if (empty($lotes)) {
            return ApiResponse::error('Debe agregar al menos un lote a la guía.');
        }

        // 2. Generar los QR tokens (UUID v4) desde el backend
        $qrTransportista = (string) Str::uuid();
        $qrRemitente = (string) Str::uuid();

        // 3. Guardar evidencias físicas en disco
        $evidenciasGuardadas = [];
        if (! empty($archivos)) {
            $evidenciasGuardadas = ArchivoHelper::guardarArchivos('guias-primer-tramo', $archivos);
        }

        // 4. Transacción: crear guía + sus lotes con correlativo individual
        try {
            DB::beginTransaction();

            $guiaId = DB::table('guia_primer_tramo')->insertGetId([
                'id_sucursal' => (int) $data['id_sucursal'],
                'id_proveedor' => (int) $data['id_proveedor'],
                'id_concesion' => (int) $data['id_concesion'],
                'id_conductor' => (int) $data['id_conductor'],
                'id_vehiculo' => (int) $data['id_vehiculo'],
                'id_empresa_transporte' => isset($data['id_empresa_transporte']) && $data['id_empresa_transporte'] !== null
                    ? (int) $data['id_empresa_transporte']
                    : null,
                'id_vehiculo_carreta' => isset($data['id_vehiculo_carreta']) && $data['id_vehiculo_carreta'] !== null
                    ? (int) $data['id_vehiculo_carreta']
                    : null,
                'id_empresa_transporte_carreta' => isset($data['id_empresa_transporte_carreta']) && $data['id_empresa_transporte_carreta'] !== null
                    ? (int) $data['id_empresa_transporte_carreta']
                    : null,
                'qr_token_transportista' => $qrTransportista,
                'qr_token_remitente' => $qrRemitente,
                'motivo_traslado' => $data['motivo_traslado'],
                'evidencias' => ! empty($evidenciasGuardadas) ? json_encode($evidenciasGuardadas) : null,
                'fecha_inicio_traslado' => $data['fecha_inicio_traslado'] ?? null,
                'fecha_emision' => $data['fecha_emision'] ?? null,
                'fecha_en_planta' => $data['fecha_en_planta'] ?? null,
                'serie_guia_remitente' => $data['serie_guia_remitente'] ?? null,
                'numero_guia_remitente' => $data['numero_guia_remitente'] ?? null,
                'serie_guia_transportista' => $data['serie_guia_transportista'] ?? null,
                'numero_guia_transportista' => $data['numero_guia_transportista'] ?? null,
                'sin_guia_transportista' => ! empty($data['sin_guia_transportista']),
                'estado' => \App\Shared\Enums\_Generic\EstadoBase::Activo->value,
                'created_at' => now()->toDateTimeString(),
            ]);

            // 5. Crear los lotes de la guía con su correlativo individual.
           
           
            foreach ($lotes as $idx => $lote) {
                $pesoBruto = (float) ($lote['peso_bruto'] ?? 0);
                $tara = (float) ($lote['tara'] ?? 0);
                $pesoNeto = $pesoBruto - $tara;

                $correlativoData = CorrelativoHelper::generar(
                    tabla: 'lote_guia',
                    prefijo: 'FB',
                    filtros: [],
                    longitudCeros: 5,
                    reseteo: Periodo::Anual
                );

                DB::table('lote_guia')->insert([
                    'id_guia_primer_tramo' => $guiaId,
                    'id_lote_mineral' => (int) $lote['id_lote_mineral'],
                    'correlativo' => $correlativoData['correlativo'],
                    'numero_correlativo' => $correlativoData['numero_correlativo'],
                    'peso_bruto' => $pesoBruto,
                    'tara' => $tara,
                    'peso_neto' => $pesoNeto,
                ]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return ApiResponse::error('Error al registrar la guía: '.$e->getMessage());
        }

        $guiaCreada = GuiasPrimerTramoData::get_guia_by_id($guiaId);

        return ApiResponse::success($guiaCreada, 'Guía de primer tramo registrada correctamente.');
    }

    /**
     * Actualizar una guía de primer tramo con sus lotes y evidencias.
     */
    public static function actualizar_guia(int $id, array $data, array $lotes, array $archivos): array
    {
        // 1. Validaciones mínimas
        if (empty($lotes)) {
            return ApiResponse::error('Debe agregar al menos un lote a la guía.');
        }

        try {
            DB::beginTransaction();

            $guia = DB::table('guia_primer_tramo')->where('id', $id)->first();
            if (! $guia) {
                DB::rollBack();
                return ApiResponse::error('No se encontró la guía de primer tramo.');
            }

            // 2. Procesar evidencias (conserver existentes + guardar nuevas)
            $evidenciasGuardadas = [];
            if (array_key_exists('evidencias_existentes', $data)) {
                $evidenciasGuardadas = is_array($data['evidencias_existentes'])
                    ? $data['evidencias_existentes']
                    : (json_decode($data['evidencias_existentes'], true) ?? []);
            } else {
                $evidenciasGuardadas = isset($guia->evidencias) ? json_decode($guia->evidencias, true) ?? [] : [];
            }

            if (! empty($archivos)) {
                $nuevasEvidencias = ArchivoHelper::guardarArchivos('guias-primer-tramo', $archivos);
                $evidenciasGuardadas = array_merge($evidenciasGuardadas, $nuevasEvidencias);
            }

            // 3. Actualizar la guía
            DB::table('guia_primer_tramo')->where('id', $id)->update([
                'id_sucursal' => (int) $data['id_sucursal'],
                'id_proveedor' => (int) $data['id_proveedor'],
                'id_concesion' => (int) $data['id_concesion'],
                'id_conductor' => (int) $data['id_conductor'],
                'id_vehiculo' => (int) $data['id_vehiculo'],
                'id_empresa_transporte' => isset($data['id_empresa_transporte']) && $data['id_empresa_transporte'] !== null
                    ? (int) $data['id_empresa_transporte']
                    : null,
                'id_vehiculo_carreta' => isset($data['id_vehiculo_carreta']) && $data['id_vehiculo_carreta'] !== null
                    ? (int) $data['id_vehiculo_carreta']
                    : null,
                'id_empresa_transporte_carreta' => isset($data['id_empresa_transporte_carreta']) && $data['id_empresa_transporte_carreta'] !== null
                    ? (int) $data['id_empresa_transporte_carreta']
                    : null,
                'motivo_traslado' => $data['motivo_traslado'],
                'evidencias' => ! empty($evidenciasGuardadas) ? json_encode(array_values($evidenciasGuardadas)) : null,
                'fecha_inicio_traslado' => $data['fecha_inicio_traslado'] ?? null,
                'fecha_emision' => $data['fecha_emision'] ?? null,
                'fecha_en_planta' => $data['fecha_en_planta'] ?? null,
                'serie_guia_remitente' => $data['serie_guia_remitente'] ?? null,
                'numero_guia_remitente' => $data['numero_guia_remitente'] ?? null,
                'serie_guia_transportista' => $data['serie_guia_transportista'] ?? null,
                'numero_guia_transportista' => $data['numero_guia_transportista'] ?? null,
                'sin_guia_transportista' => ! empty($data['sin_guia_transportista']),
            ]);

            // 4. Sincronizar lotes en la tabla `lote_guia`
            $lotesExistentes = DB::table('lote_guia')
                ->where('id_guia_primer_tramo', $id)
                ->orderBy('numero_correlativo', 'ASC')
                ->get();

            // Guardamos el pool de correlativos existentes para re-utilizarlos en orden
            $poolCorrelativos = [];
            foreach ($lotesExistentes as $item) {
                $poolCorrelativos[] = [
                    'numero' => $item->numero_correlativo,
                    'correlativo' => $item->correlativo
                ];
            }

            $nuevosLotesIds = [];
            $existentesMap = $lotesExistentes->keyBy('id_lote_mineral');

            foreach ($lotes as $idx => $lote) {
                $idLoteMineral = (int) $lote['id_lote_mineral'];
                $pesoBruto = (float) ($lote['peso_bruto'] ?? 0);
                $tara = (float) ($lote['tara'] ?? 0);
                $pesoNeto = $pesoBruto - $tara;

                $nuevosLotesIds[] = $idLoteMineral;

                // Asignar correlativo del pool o generar uno nuevo si es una adición
                if (isset($poolCorrelativos[$idx])) {
                    $numeroCorrelativo = $poolCorrelativos[$idx]['numero'];
                    $correlativoStr = $poolCorrelativos[$idx]['correlativo'];
                } else {
                    $correlativoData = CorrelativoHelper::generar(
                        tabla: 'lote_guia',
                        prefijo: 'FB',
                        filtros: [],
                        longitudCeros: 5,
                        reseteo: Periodo::Anual
                    );
                    $numeroCorrelativo = $correlativoData['numero_correlativo'];
                    $correlativoStr = $correlativoData['correlativo'];
                }

                if ($existentesMap->has($idLoteMineral)) {
                    // Si ya existía, actualizamos pesos y su nuevo correlativo/orden
                    $existente = $existentesMap->get($idLoteMineral);
                    DB::table('lote_guia')
                        ->where('id', $existente->id)
                        ->update([
                            'correlativo' => $correlativoStr,
                            'numero_correlativo' => $numeroCorrelativo,
                            'peso_bruto' => $pesoBruto,
                            'tara' => $tara,
                            'peso_neto' => $pesoNeto,
                        ]);
                } else {
                    // Si es una nueva adición
                    DB::table('lote_guia')->insert([
                        'id_guia_primer_tramo' => $id,
                        'id_lote_mineral' => $idLoteMineral,
                        'correlativo' => $correlativoStr,
                        'numero_correlativo' => $numeroCorrelativo,
                        'peso_bruto' => $pesoBruto,
                        'tara' => $tara,
                        'peso_neto' => $pesoNeto,
                    ]);
                }
            }

            // Eliminar lotes que ya no estén asociados
            DB::table('lote_guia')
                ->where('id_guia_primer_tramo', $id)
                ->whereNotIn('id_lote_mineral', $nuevosLotesIds)
                ->delete();

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponse::error('Error al actualizar la guía: '.$e->getMessage());
        }

        $guiaActualizada = GuiasPrimerTramoData::get_guia_by_id($id);
        return ApiResponse::success($guiaActualizada, 'Guía de primer tramo actualizada correctamente.');
    }

    /**
     * Anular una guía de primer tramo (cambiar estado a Inactivo).
     */
    public static function anular_guia(int $id): array
    {
        try {
            DB::beginTransaction();

            $guia = DB::table('guia_primer_tramo')->where('id', $id)->first();
            if (! $guia) {
                DB::rollBack();
                return ApiResponse::error('No se encontró la guía de primer tramo.');
            }

            DB::table('guia_primer_tramo')->where('id', $id)->update([
                'estado' => \App\Shared\Enums\_Generic\EstadoBase::Inactivo->value,
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponse::error('Error al anular la guía: '.$e->getMessage());
        }

        $guiaAnulada = GuiasPrimerTramoData::get_guia_by_id($id);
        return ApiResponse::success($guiaAnulada, 'Guía de primer tramo anulada correctamente.');
    }
}
