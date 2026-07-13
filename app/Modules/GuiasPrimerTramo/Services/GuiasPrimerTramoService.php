<?php

namespace App\Modules\GuiasPrimerTramo\Services;

use App\Modules\GuiasPrimerTramo\Data\GuiasPrimerTramoData;
use App\Modules\GuiasPrimerTramo\Data\GuiasPrimerTramoHistorialData;
use App\Modules\GuiasPrimerTramo\Helpers\HistorialDiff;
use App\Modules\GuiasPrimerTramo\Helpers\HistorialLookup;
use App\Modules\GuiasPrimerTramo\Helpers\HistorialUsuarioHelper;
use App\Shared\Enums\_Generic\EstadoBase;
use App\Shared\Enums\_Generic\Periodo;
use App\Shared\Enums\GuiaPrimerTramoHistorialAccion;
use App\Shared\Enums\LoteGuiaHistorialAccion;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Helpers\CorrelativoHelper;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GuiasPrimerTramoService
{
    /**
     * Campos de la cabecera que se rastrean en historial (todos los editables).
     * Excluye: id, qr_token_*, estado (cambia solo vía anular), created_at.
     */
    private const CAMPOS_CABECERA = [
        'id_sucursal',
        'id_proveedor',
        'id_concesion',
        'id_conductor',
        'id_vehiculo',
        'id_empresa_transporte',
        'id_vehiculo_carreta',
        'id_empresa_transporte_carreta',
        'motivo_traslado',
        'fecha_inicio_traslado',
        'fecha_emision',
        'fecha_en_planta',
        'serie_guia_remitente',
        'numero_guia_remitente',
        'serie_guia_transportista',
        'numero_guia_transportista',
        'sin_guia_transportista',
    ];

    /**
     * Campos de un lote que se rastrean en historial.
     */
    private const CAMPOS_LOTE = [
        'correlativo',
        'numero_correlativo',
        'peso_bruto',
        'tara',
        'peso_neto',
    ];

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
     * Obtener el historial (cabecera + lotes) de una guía, en orden cronológico DESC.
     */
    public static function get_historial(int $idGuia): array
    {
        $guia = DB::table('guia_primer_tramo')->where('id', $idGuia)->exists();
        if (! $guia) {
            return ApiResponse::error('No se encontró la guía de primer tramo.');
        }

        $historial = GuiasPrimerTramoHistorialData::get_historial($idGuia);

        return ApiResponse::success($historial, 'Historial de la guía obtenido correctamente.');
    }

    /**
     * Crear una nueva guía de primer tramo con sus lotes.
     */
    public static function crear_guia(array $data, array $lotes, array $archivos, ?Request $request = null): array
    {
        if (empty($lotes)) {
            return ApiResponse::error('Debe agregar al menos un lote a la guía.');
        }

        $qrTransportista = (string) Str::uuid();
        $qrRemitente = (string) Str::uuid();

        $evidenciasGuardadas = [];
        if (! empty($archivos)) {
            $evidenciasGuardadas = ArchivoHelper::guardarArchivos('guias-primer-tramo', $archivos);
        }

        try {
            DB::beginTransaction();

            $valoresNuevos = [
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
                'evidencias' => ! empty($evidenciasGuardadas) ? $evidenciasGuardadas : null,
                'fecha_inicio_traslado' => $data['fecha_inicio_traslado'] ?? null,
                'fecha_emision' => $data['fecha_emision'] ?? null,
                'fecha_en_planta' => $data['fecha_en_planta'] ?? null,
                'serie_guia_remitente' => $data['serie_guia_remitente'] ?? null,
                'numero_guia_remitente' => $data['numero_guia_remitente'] ?? null,
                'serie_guia_transportista' => $data['serie_guia_transportista'] ?? null,
                'numero_guia_transportista' => $data['numero_guia_transportista'] ?? null,
                'sin_guia_transportista' => ! empty($data['sin_guia_transportista']),
                'estado' => EstadoBase::Activo->value,
            ];

            $valoresNuevos['created_at'] = now()->toDateTimeString();

            // evidencia se guarda como JSON en la BD pero la lógica de DB::insert acepta array → se serializa automático.
            // Si preferimos explícito, usar json_encode abajo. Aquí usamos array y Laravel lo serializa.
            $insertGuia = $valoresNuevos;
            $insertGuia['evidencias'] = ! empty($evidenciasGuardadas) ? json_encode($evidenciasGuardadas) : null;

            $guiaId = DB::table('guia_primer_tramo')->insertGetId($insertGuia);

            $lotesInsertados = [];
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

                $loteId = DB::table('lote_guia')->insertGetId([
                    'id_guia_primer_tramo' => $guiaId,
                    'id_lote_mineral' => (int) $lote['id_lote_mineral'],
                    'correlativo' => $correlativoData['correlativo'],
                    'numero_correlativo' => $correlativoData['numero_correlativo'],
                    'peso_bruto' => $pesoBruto,
                    'tara' => $tara,
                    'peso_neto' => $pesoNeto,
                ]);

                $lotesInsertados[] = [
                    'id' => $loteId,
                    'id_guia_primer_tramo' => $guiaId,
                    'id_lote_mineral' => (int) $lote['id_lote_mineral'],
                    'correlativo' => $correlativoData['correlativo'],
                    'numero_correlativo' => $correlativoData['numero_correlativo'],
                    'peso_bruto' => $pesoBruto,
                    'tara' => $tara,
                    'peso_neto' => $pesoNeto,
                ];
            }

            $usuarioHistorial = HistorialUsuarioHelper::resolverDesdeRequest($request);

            // Historial cabecera: CREADO (solo valores_nuevos, no hay anteriores)
            DB::table('guia_primer_tramo_historial')->insert([
                'id_guia_primer_tramo' => $guiaId,
                'accion' => GuiaPrimerTramoHistorialAccion::Creado->value,
                'id_usuario' => $usuarioHistorial['id_usuario'],
                'usuario_nombre' => $usuarioHistorial['usuario_nombre'],
                'cambios' => null,
                'valores_anteriores' => null,
                'valores_nuevos' => self::snapshotSinJson($valoresNuevos),
                'created_at' => now()->toDateTimeString(),
            ]);

            // Historial lotes: LOTE_CREADO por cada uno
            foreach ($lotesInsertados as $loteInsert) {
                DB::table('lote_guia_historial')->insert([
                    'id_lote_guia' => $loteInsert['id'],
                    'id_guia_primer_tramo' => $guiaId,
                    'id_lote_mineral' => $loteInsert['id_lote_mineral'],
                    'accion' => LoteGuiaHistorialAccion::LoteCreado->value,
                    'id_usuario' => $usuarioHistorial['id_usuario'],
                    'usuario_nombre' => $usuarioHistorial['usuario_nombre'],
                    'cambios' => null,
                    'valores_anteriores' => null,
                    'valores_nuevos' => self::snapshotLote($loteInsert),
                    'created_at' => now()->toDateTimeString(),
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
    public static function actualizar_guia(int $id, array $data, array $lotes, array $archivos, ?Request $request = null): array
    {
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

            $valoresAnterioresCab = self::rowToArray($guia);

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

            $nuevosValoresCab = [
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
                'evidencias' => $evidenciasGuardadas,
                'fecha_inicio_traslado' => $data['fecha_inicio_traslado'] ?? null,
                'fecha_emision' => $data['fecha_emision'] ?? null,
                'fecha_en_planta' => $data['fecha_en_planta'] ?? null,
                'serie_guia_remitente' => $data['serie_guia_remitente'] ?? null,
                'numero_guia_remitente' => $data['numero_guia_remitente'] ?? null,
                'serie_guia_transportista' => $data['serie_guia_transportista'] ?? null,
                'numero_guia_transportista' => $data['numero_guia_transportista'] ?? null,
                'sin_guia_transportista' => ! empty($data['sin_guia_transportista']),
            ];

            DB::table('guia_primer_tramo')->where('id', $id)->update([
                'id_sucursal' => $nuevosValoresCab['id_sucursal'],
                'id_proveedor' => $nuevosValoresCab['id_proveedor'],
                'id_concesion' => $nuevosValoresCab['id_concesion'],
                'id_conductor' => $nuevosValoresCab['id_conductor'],
                'id_vehiculo' => $nuevosValoresCab['id_vehiculo'],
                'id_empresa_transporte' => $nuevosValoresCab['id_empresa_transporte'],
                'id_vehiculo_carreta' => $nuevosValoresCab['id_vehiculo_carreta'],
                'id_empresa_transporte_carreta' => $nuevosValoresCab['id_empresa_transporte_carreta'],
                'motivo_traslado' => $nuevosValoresCab['motivo_traslado'],
                'evidencias' => ! empty($evidenciasGuardadas) ? json_encode(array_values($evidenciasGuardadas)) : null,
                'fecha_inicio_traslado' => $nuevosValoresCab['fecha_inicio_traslado'],
                'fecha_emision' => $nuevosValoresCab['fecha_emision'],
                'fecha_en_planta' => $nuevosValoresCab['fecha_en_planta'],
                'serie_guia_remitente' => $nuevosValoresCab['serie_guia_remitente'],
                'numero_guia_remitente' => $nuevosValoresCab['numero_guia_remitente'],
                'serie_guia_transportista' => $nuevosValoresCab['serie_guia_transportista'],
                'numero_guia_transportista' => $nuevosValoresCab['numero_guia_transportista'],
                'sin_guia_transportista' => $nuevosValoresCab['sin_guia_transportista'],
            ]);

            // Diff cabecera (campos top-level)
            $diffCabecera = HistorialDiff::calcular(
                self::rowToArray($guia),
                $nuevosValoresCab,
                self::CAMPOS_CABECERA
            );

            // Diff evidencias: comparamos contra el json crudo del registro original (decoded)
            $oldEvidencias = json_decode($guia->evidencias ?? '[]', true) ?: [];
            $diffEvidencias = HistorialDiff::diffEvidencias($oldEvidencias, $evidenciasGuardadas);
            if (! empty($diffEvidencias['agregados']) || ! empty($diffEvidencias['eliminados'])) {
                $diffCabecera['evidencias'] = [
                    'anterior' => [
                        'total' => count($oldEvidencias),
                        'nombres' => $diffEvidencias['eliminados'],
                    ],
                    'nuevo' => [
                        'total' => count($evidenciasGuardadas),
                        'nombres' => $diffEvidencias['agregados'],
                    ],
                ];
            }

            // Reemplaza los IDs de FK por nombres legibles (id_vehiculo -> "ABC-123", etc.)
            $diffCabecera = HistorialLookup::enrichDiff($diffCabecera);

            // Sincronizar lotes
            $usuarioHistorial = HistorialUsuarioHelper::resolverDesdeRequest($request);

            $lotesExistentes = DB::table('lote_guia')
                ->where('id_guia_primer_tramo', $id)
                ->orderBy('numero_correlativo', 'ASC')
                ->get();

            $poolCorrelativos = [];
            foreach ($lotesExistentes as $item) {
                $poolCorrelativos[] = [
                    'numero' => $item->numero_correlativo,
                    'correlativo' => $item->correlativo,
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

                $nuevoSnapshot = [
                    'id_guia_primer_tramo' => $id,
                    'id_lote_mineral' => $idLoteMineral,
                    'correlativo' => $correlativoStr,
                    'numero_correlativo' => $numeroCorrelativo,
                    'peso_bruto' => $pesoBruto,
                    'tara' => $tara,
                    'peso_neto' => $pesoNeto,
                ];

                if ($existentesMap->has($idLoteMineral)) {
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
                    $loteInsert = $nuevoSnapshot;
                    $loteId = DB::table('lote_guia')->insertGetId($loteInsert);
                    $existente = (object) array_merge(['id' => $loteId], $loteInsert);
                }

                // Recalculamos con el row actualizado (id incluido) para snapshot final
                $rowPost = DB::table('lote_guia')->where('id_lote_mineral', $idLoteMineral)->where('id_guia_primer_tramo', $id)->first();
                $rowPostArr = self::rowToArray($rowPost);

                if ($existentesMap->has($idLoteMineral)) {
                    // Edición: comparar old vs new (sólo campos que efectivamente cambiaron)
                    $diffLote = HistorialDiff::calcular(
                        self::rowToArray($existentesMap->get($idLoteMineral)),
                        $rowPostArr,
                        self::CAMPOS_LOTE
                    );
                    if (! empty($diffLote)) {
                        self::persistirHistorialLote(
                            idGuia: $id,
                            idLoteGuia: (int) $rowPost->id,
                            idLoteMineral: (int) $rowPost->id_lote_mineral,
                            accion: LoteGuiaHistorialAccion::LoteEditado,
                            cambios: $diffLote,
                            valoresAnteriores: self::snapshotLote(self::rowToArray($existentesMap->get($idLoteMineral))),
                            valoresNuevos: self::snapshotLote($rowPostArr),
                            usuarioHistorial: $usuarioHistorial
                        );
                    }
                } else {
                    // Lote nuevo: poblar cambios con campos clave + correlativo del lote mineral
                    $correlativoLoteMineral = HistorialLookup::loteMineralCorrelativo($idLoteMineral);
                    $cambiosLoteNuevo = [
                        'lote_agregado' => [
                            'anterior' => null,
                            'nuevo' => $correlativoLoteMineral,
                        ],
                        'peso_bruto' => [
                            'anterior' => null,
                            'nuevo' => $pesoBruto,
                        ],
                        'tara' => [
                            'anterior' => null,
                            'nuevo' => $tara,
                        ],
                        'peso_neto' => [
                            'anterior' => null,
                            'nuevo' => $pesoNeto,
                        ],
                    ];
                    self::persistirHistorialLote(
                        idGuia: $id,
                        idLoteGuia: (int) $rowPost->id,
                        idLoteMineral: (int) $rowPost->id_lote_mineral,
                        accion: LoteGuiaHistorialAccion::LoteCreado,
                        cambios: $cambiosLoteNuevo,
                        valoresAnteriores: null,
                        valoresNuevos: self::snapshotLote($rowPostArr),
                        usuarioHistorial: $usuarioHistorial
                    );
                }
            }

            // Eliminar lotes removidos y registrar eliminación
            $lotesEliminados = DB::table('lote_guia')
                ->where('id_guia_primer_tramo', $id)
                ->whereNotIn('id_lote_mineral', $nuevosLotesIds)
                ->get();

            foreach ($lotesEliminados as $eliminado) {
                $snapshotEliminado = self::rowToArray($eliminado);
                $correlativoLoteMineral = HistorialLookup::loteMineralCorrelativo((int) $eliminado->id_lote_mineral);
                $cambiosLoteEliminado = [
                    'lote_eliminado' => [
                        'anterior' => $correlativoLoteMineral,
                        'nuevo' => null,
                    ],
                    'peso_bruto' => [
                        'anterior' => $eliminado->peso_bruto,
                        'nuevo' => null,
                    ],
                    'tara' => [
                        'anterior' => $eliminado->tara,
                        'nuevo' => null,
                    ],
                    'peso_neto' => [
                        'anterior' => $eliminado->peso_neto,
                        'nuevo' => null,
                    ],
                ];
                self::persistirHistorialLote(
                    idGuia: $id,
                    idLoteGuia: (int) $eliminado->id,
                    idLoteMineral: (int) $eliminado->id_lote_mineral,
                    accion: LoteGuiaHistorialAccion::LoteEliminado,
                    cambios: $cambiosLoteEliminado,
                    valoresAnteriores: self::snapshotLote($snapshotEliminado),
                    valoresNuevos: null,
                    usuarioHistorial: $usuarioHistorial
                );
            }

            if (! empty($lotesEliminados)) {
                DB::table('lote_guia')
                    ->where('id_guia_primer_tramo', $id)
                    ->whereNotIn('id_lote_mineral', $nuevosLotesIds)
                    ->delete();
            }

            // Persistir historial cabecera solo si hay diff real
            if (! empty($diffCabecera)) {
                DB::table('guia_primer_tramo_historial')->insert([
                    'id_guia_primer_tramo' => $id,
                    'accion' => GuiaPrimerTramoHistorialAccion::Editado->value,
                    'id_usuario' => $usuarioHistorial['id_usuario'],
                    'usuario_nombre' => $usuarioHistorial['usuario_nombre'],
                    'cambios' => json_encode($diffCabecera),
                    'valores_anteriores' => self::snapshotCabecera($valoresAnterioresCab),
                    'valores_nuevos' => self::snapshotCabecera($nuevosValoresCab),
                    'created_at' => now()->toDateTimeString(),
                ]);
            }

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
     * Se registra como EDITADO con diff único en el campo `estado`.
     */
    public static function anular_guia(int $id, ?Request $request = null): array
    {
        try {
            DB::beginTransaction();

            $guia = DB::table('guia_primer_tramo')->where('id', $id)->first();
            if (! $guia) {
                DB::rollBack();

                return ApiResponse::error('No se encontró la guía de primer tramo.');
            }

            $estadoAnterior = $guia->estado;

            DB::table('guia_primer_tramo')->where('id', $id)->update([
                'estado' => EstadoBase::Inactivo->value,
            ]);

            $usuarioHistorial = HistorialUsuarioHelper::resolverDesdeRequest($request);

            DB::table('guia_primer_tramo_historial')->insert([
                'id_guia_primer_tramo' => $id,
                'accion' => GuiaPrimerTramoHistorialAccion::Editado->value,
                'id_usuario' => $usuarioHistorial['id_usuario'],
                'usuario_nombre' => $usuarioHistorial['usuario_nombre'],
                'cambios' => json_encode([
                    'estado' => [
                        'anterior' => $estadoAnterior,
                        'nuevo' => EstadoBase::Inactivo->value,
                    ],
                ]),
                'valores_anteriores' => json_encode([
                    'estado' => $estadoAnterior,
                ]),
                'valores_nuevos' => json_encode([
                    'estado' => EstadoBase::Inactivo->value,
                ]),
                'created_at' => now()->toDateTimeString(),
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return ApiResponse::error('Error al anular la guía: '.$e->getMessage());
        }

        $guiaAnulada = GuiasPrimerTramoData::get_guia_by_id($id);

        return ApiResponse::success($guiaAnulada, 'Guía de primer tramo anulada correctamente.');
    }

    /**
     * Persiste una fila en lote_guia_historial.
     *
     * @param  array<string, mixed>|null  $cambios  Diff campo-a-campo (se serializa aquí).
     * @param  string|null  $valoresAnteriores  Snapshot completo ya serializado en JSON (o null).
     * @param  string|null  $valoresNuevos  Snapshot completo ya serializado en JSON (o null).
     * @param  array{id_usuario: int, usuario_nombre: string}  $usuarioHistorial
     */
    private static function persistirHistorialLote(
        int $idGuia,
        int $idLoteGuia,
        int $idLoteMineral,
        LoteGuiaHistorialAccion $accion,
        ?array $cambios,
        ?string $valoresAnteriores,
        ?string $valoresNuevos,
        array $usuarioHistorial,
    ): void {
        DB::table('lote_guia_historial')->insert([
            'id_lote_guia' => $idLoteGuia,
            'id_guia_primer_tramo' => $idGuia,
            'id_lote_mineral' => $idLoteMineral,
            'accion' => $accion->value,
            'id_usuario' => $usuarioHistorial['id_usuario'],
            'usuario_nombre' => $usuarioHistorial['usuario_nombre'],
            'cambios' => $cambios !== null ? json_encode($cambios) : null,
            'valores_anteriores' => $valoresAnteriores,
            'valores_nuevos' => $valoresNuevos,
            'created_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Convierte un stdClass (fila DB::first) en array asociativo.
     *
     * @return array<string, mixed>
     */
    private static function rowToArray(?object $row): array
    {
        if ($row === null) {
            return [];
        }

        return json_decode(json_encode($row), true) ?? [];
    }

    /**
     * Snapshot "limpio" de la cabecera para almacenar en valores_nuevos.
     * Excluye campos inmutables / derivados.
     *
     * @param  array<string, mixed>  $valores
     * @return string|null JSON serializado.
     */
    private static function snapshotCabecera(array $valores): ?string
    {
        $excluidos = ['id', 'qr_token_transportista', 'qr_token_remitente', 'created_at'];
        $filtrado = array_filter(
            $valores,
            static fn ($key) => ! in_array($key, $excluidos, true),
            ARRAY_FILTER_USE_KEY
        );

        return json_encode($filtrado, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Snapshot "limpio" de un lote para almacenar en valores_nuevos.
     *
     * @param  array<string, mixed>  $valores
     */
    private static function snapshotLote(array $valores): string
    {
        return json_encode($valores, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Snapshot sin serializar (cuando el caller ya inserta con DB::table y serializa explícito).
     * Usado solo en crear_guia para construir el array que se almacenará.
     *
     * @param  array<string, mixed>  $valores
     * @return string JSON.
     */
    private static function snapshotSinJson(array $valores): string
    {
        $excluidos = ['id', 'qr_token_transportista', 'qr_token_remitente', 'created_at'];
        $filtrado = array_filter(
            $valores,
            static fn ($key) => ! in_array($key, $excluidos, true),
            ARRAY_FILTER_USE_KEY
        );

        return json_encode($filtrado, JSON_UNESCAPED_UNICODE);
    }
}
