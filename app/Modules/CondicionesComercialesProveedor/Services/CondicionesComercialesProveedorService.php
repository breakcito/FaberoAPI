<?php

namespace App\Modules\CondicionesComercialesProveedor\Services;

use App\Models\CondicionComercialProveedor;
use App\Models\Proveedor;
use App\Modules\CondicionesComercialesProveedor\Data\CondicionesComercialesProveedorData;
use App\Shared\Enums\_Generic\ElementoQuimicoValorizacion;
use App\Shared\Enums\_Generic\EstadoBase;
use App\Shared\Responses\ApiResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CondicionesComercialesProveedorService
{
    /**
     * Obtener el listado de condiciones comerciales de un proveedor.
     */
    public static function get_condiciones_por_proveedor(int $idProveedor, ?string $estado = null): array
    {
        $proveedor = Proveedor::find($idProveedor);
        if (! $proveedor) {
            return ApiResponse::error('El proveedor especificado no existe.');
        }

        $data = CondicionesComercialesProveedorData::get_condiciones_por_proveedor($idProveedor, $estado);

        return ApiResponse::success($data, 'Condiciones comerciales obtenidas correctamente.');
    }

    /**
     * Crear una nueva condición comercial para un proveedor.
     */
    public static function crear_condicion(
        int $idProveedor,
        string $elementoQuimico,
        float $leyInicio,
        float $leyFinal,
        float $maquila,
        float $recuperacion,
        float $consumo,
        float $riesgoComercial
    ): array {
        $proveedor = Proveedor::find($idProveedor);
        if (! $proveedor) {
            return ApiResponse::error('El proveedor especificado no existe.');
        }

        $elementoEnum = ElementoQuimicoValorizacion::tryFrom($elementoQuimico);
        if (! $elementoEnum) {
            return ApiResponse::error('El elemento químico especificado no es válido.');
        }

        if ($leyInicio > $leyFinal) {
            return ApiResponse::error('La ley de inicio no puede ser mayor que la ley de fin.');
        }

        DB::beginTransaction();
        try {
            $condicion = CondicionComercialProveedor::create([
                'id_proveedor_minero' => $idProveedor,
                'elemento_quimico' => $elementoEnum->value,
                'ley_inicio' => $leyInicio,
                'ley_fin' => $leyFinal,
                'maquila' => $maquila,
                'recuperacion' => $recuperacion,
                'consumo' => $consumo,
                'riesgo_comercial' => $riesgoComercial,
                'estado' => EstadoBase::Activo->value,
                'created_at' => Carbon::now(),
            ]);

            DB::commit();

            $data = CondicionesComercialesProveedorData::get_condicion_por_id($condicion->id);

            return ApiResponse::success($data, 'Condición comercial registrada correctamente.');
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al registrar la condición comercial: '.$e->getMessage());
        }
    }

    /**
     * Editar una condición comercial existente.
     */
    public static function editar_condicion(
        int $id,
        string $elementoQuimico,
        float $leyInicio,
        float $leyFinal,
        float $maquila,
        float $recuperacion,
        float $consumo,
        float $riesgoComercial
    ): array {
        $condicion = CondicionComercialProveedor::find($id);
        if (! $condicion) {
            return ApiResponse::error('La condición comercial no existe.');
        }

        $elementoEnum = ElementoQuimicoValorizacion::tryFrom($elementoQuimico);
        if (! $elementoEnum) {
            return ApiResponse::error('El elemento químico especificado no es válido.');
        }

        if ($leyInicio > $leyFinal) {
            return ApiResponse::error('La ley de inicio no puede ser mayor que la ley de fin.');
        }

        DB::beginTransaction();
        try {
            $condicion->elemento_quimico = $elementoEnum->value;
            $condicion->ley_inicio = $leyInicio;
            $condicion->ley_fin = $leyFinal;
            $condicion->maquila = $maquila;
            $condicion->recuperacion = $recuperacion;
            $condicion->consumo = $consumo;
            $condicion->riesgo_comercial = $riesgoComercial;
            $condicion->save();

            DB::commit();

            $data = CondicionesComercialesProveedorData::get_condicion_por_id($id);

            return ApiResponse::success($data, 'Condición comercial actualizada correctamente.');
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al actualizar la condición comercial: '.$e->getMessage());
        }
    }

    /**
     * Cambiar el estado (Activo / Inactivo) de una condición comercial.
     */
    public static function cambiar_estado_condicion(int $id, string $nuevoEstado): array
    {
        $condicion = CondicionComercialProveedor::find($id);
        if (! $condicion) {
            return ApiResponse::error('La condición comercial no existe.');
        }

        $estadoEnum = EstadoBase::tryFrom($nuevoEstado);
        if (! $estadoEnum) {
            return ApiResponse::error('El estado especificado no es válido.');
        }

        DB::beginTransaction();
        try {
            $condicion->estado = $estadoEnum->value;
            $condicion->save();

            DB::commit();

            $data = CondicionesComercialesProveedorData::get_condicion_por_id($id);

            return ApiResponse::success($data, 'Estado de la condición comercial actualizado correctamente.');
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al cambiar el estado: '.$e->getMessage());
        }
    }
}
