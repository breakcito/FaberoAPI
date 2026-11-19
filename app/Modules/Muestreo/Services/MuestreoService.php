<?php

namespace App\Modules\Muestreo\Services;

use App\Models\LoteMineral;
use App\Modules\Muestreo\Data\MuestreoData;
use App\Shared\Enums\_Generic\EstadoBase;
use App\Shared\Responses\_Generic\RES_CambiosLog;
use App\Shared\Responses\ApiResponse;
use Illuminate\Support\Facades\DB;

class MuestreoService
{
    /**
     * Listado de lotes pendientes de muestreo.
     *
     * @param  array{fecha_inicio?: string|null, fecha_fin?: string|null}  $filtros
     */
    public static function get_lotes(array $filtros): array
    {
        return ApiResponse::success(
            MuestreoData::get_lotes_pendientes($filtros),
            'Lotes pendientes de muestreo obtenidos correctamente'
        );
    }

    /**
     * Detalle de un lote pendiente de muestreo, con sus particiones activas.
     */
    public static function get_lote(int $id): array
    {
        $lotes = MuestreoData::get_lotes_pendientes([]);
        $lote = null;

        foreach ($lotes as $item) {
            if ((int) $item->id === $id) {
                $lote = $item;
                break;
            }
        }

        if (! $lote) {
            return ApiResponse::error('No se encontró el lote pendiente de muestreo.', 404);
        }

        return ApiResponse::success($lote);
    }

    /**
     * Confirmar el muestreo de un lote.
     *
     * Define el tipo de producto y el tipo de mineral, y consolida el
     * correlativo (el usuario puede mantenerlo o corregirlo). Al confirmar:
     *  - el lote queda `con_codigo_temporal = 0` y `muestreo_confirmado = 1`,
     *  - todas sus particiones activas quedan confirmadas y con el correlativo
     *    realineado a `{correlativo}-{letra}`,
     *  - el cambio queda registrado en `log_cambios`.
     *
     * @param  array{tipo_producto: string, tipo_mineral: string, correlativo: string, numero_correlativo?: int|null}  $data
     */
    public static function confirmar_muestreo(int $idLote, int $idEmpleado, array $data): array
    {
        $lote = LoteMineral::find($idLote);

        if (! $lote) {
            return ApiResponse::error('No se encontró el lote.', 404);
        }

        if ($lote->estado !== EstadoBase::Activo) {
            return ApiResponse::error('El lote no está activo.', 422);
        }

        if (! $lote->con_codigo_temporal && $lote->muestreo_confirmado) {
            return ApiResponse::error('El muestreo de este lote ya fue confirmado.', 422);
        }

        $correlativo = strtoupper(trim((string) ($data['correlativo'] ?? '')));
        if ($correlativo === '') {
            return ApiResponse::error('El correlativo es obligatorio.', 422);
        }

        $numeroCorrelativo = array_key_exists('numero_correlativo', $data) && $data['numero_correlativo'] !== null
            ? (int) $data['numero_correlativo']
            : null;

        $tipoProducto = (string) $data['tipo_producto'];
        $tipoMineral = (string) $data['tipo_mineral'];

        $particiones = MuestreoData::get_particiones_de_lote($idLote);

        // Unicidad del correlativo: ni el del lote ni los derivados de sus
        // particiones pueden repetir uno ya usado por otro lote.
        if (MuestreoData::existe_correlativo($correlativo, $idLote)) {
            return ApiResponse::error('El correlativo "'.$correlativo.'" ya está en uso por otro lote.', 409);
        }

        foreach ($particiones as $particion) {
            $correlativoParticion = $correlativo.'-'.$particion->particion;
            if (MuestreoData::existe_correlativo($correlativoParticion, $idLote)) {
                return ApiResponse::error(
                    'El correlativo "'.$correlativoParticion.'" de la partición '.$particion->particion.' ya está en uso.',
                    409
                );
            }
        }

        try {
            DB::transaction(function () use ($lote, $idEmpleado, $tipoProducto, $tipoMineral, $correlativo, $numeroCorrelativo, $particiones) {
                $cambios = [];

                if ($lote->tipo_producto !== $tipoProducto) {
                    $cambios[] = [
                        'campo_bd' => 'tipo_producto',
                        'campo' => 'Tipo de producto',
                        'valor_anterior' => $lote->tipo_producto ?? '— (vacío)',
                        'valor_nuevo' => $tipoProducto,
                    ];
                }

                if ($lote->tipo_mineral !== $tipoMineral) {
                    $cambios[] = [
                        'campo_bd' => 'tipo_mineral',
                        'campo' => 'Tipo de mineral',
                        'valor_anterior' => $lote->tipo_mineral ?? '— (vacío)',
                        'valor_nuevo' => $tipoMineral,
                    ];
                }

                if ($lote->correlativo !== $correlativo) {
                    $cambios[] = [
                        'campo_bd' => 'correlativo',
                        'campo' => 'Correlativo del lote',
                        'valor_anterior' => $lote->correlativo,
                        'valor_nuevo' => $correlativo,
                    ];
                }

                if ((int) $lote->numero_correlativo !== (int) $numeroCorrelativo) {
                    $cambios[] = [
                        'campo_bd' => 'numero_correlativo',
                        'campo' => 'N° correlativo',
                        'valor_anterior' => $lote->numero_correlativo ?? '— (vacío)',
                        'valor_nuevo' => $numeroCorrelativo ?? '— (vacío)',
                    ];
                }

                $cambios[] = [
                    'campo_bd' => 'con_codigo_temporal',
                    'campo' => 'Código temporal',
                    'valor_anterior' => $lote->con_codigo_temporal ? 'Sí' : 'No',
                    'valor_nuevo' => 'No',
                ];

                $cambios[] = [
                    'campo_bd' => 'muestreo_confirmado',
                    'campo' => 'Muestreo confirmado',
                    'valor_anterior' => 'No',
                    'valor_nuevo' => 'Sí',
                ];

                $lote->tipo_producto = $tipoProducto;
                $lote->tipo_mineral = $tipoMineral;
                $lote->correlativo = $correlativo;
                $lote->numero_correlativo = $numeroCorrelativo;
                $lote->con_codigo_temporal = false;
                $lote->muestreo_confirmado = true;
                $lote->save();

                // Las particiones heredan el correlativo del padre y quedan
                // muestreadas junto con él.
                foreach ($particiones as $particion) {
                    DB::table('particion_lote_mineral')
                        ->where('id', $particion->id)
                        ->update([
                            'correlativo' => $correlativo.'-'.$particion->particion,
                            'con_codigo_temporal' => false,
                            'muestreo_confirmado' => true,
                        ]);
                }

                $log = $lote->log_cambios ?? [];
                if (! is_array($log)) {
                    $log = [];
                }
                $log[] = RES_CambiosLog::crear($idEmpleado, 'Confirmación de muestreo', $cambios);
                $lote->log_cambios = $log;
                $lote->save();
            });
        } catch (\Throwable $e) {
            return ApiResponse::error('Error al confirmar el muestreo: '.$e->getMessage(), 500);
        }

        $lote->refresh();

        return ApiResponse::success([
            'lote' => $lote,
            'particiones' => MuestreoData::get_particiones_de_lote($idLote),
        ], 'Muestreo confirmado correctamente.');
    }
}
