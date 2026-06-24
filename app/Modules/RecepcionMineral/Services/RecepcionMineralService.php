<?php

namespace App\Modules\RecepcionMineral\Services;

use App\Models\RecepcionUnidad;
use App\Models\Vehiculo;
use App\Models\LoteMineral;
use App\Modules\RecepcionMineral\Data\RecepcionMineralData;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Helpers\CorrelativoHelper;
use App\Shared\Enums\_Generic\Periodo;
use App\Shared\Responses\ApiResponse;
use Illuminate\Support\Facades\DB;

class RecepcionMineralService
{
    /**
     * Obtener listado de recepciones filtradas por sucursal
     */
    public static function get_recepciones_mineral(array $filters): array
    {
        if (empty($filters['id_sucursal'])) {
            return ApiResponse::error('Debe seleccionar una sucursal.');
        }

        $data = RecepcionMineralData::get_recepciones_mineral($filters);
        return ApiResponse::success($data, 'Recepciones obtenidas correctamente');
    }

    /**
     * Iniciar el proceso de pesaje
     */
    public static function iniciar_pesaje(int $id): array
    {
        $recepcion = RecepcionUnidad::find($id);
        if (!$recepcion) {
            return ApiResponse::error('No se encontró el registro de recepción.');
        }

        $recepcion->estado_pesaje = 'En Proceso';
        $recepcion->fecha_hora_inicio_pesaje = now()->toDateTimeString();
        $recepcion->save();

        $updated = RecepcionMineralData::get_recepcion_by_id_with_lotes($id);
        return ApiResponse::success($updated, 'Proceso de pesaje iniciado correctamente.');
    }

    /**
     * Validar y actualizar un campo del registro de recepción o vehículo de forma dinámica
     */
    public static function validar_campo(int $id, string $field, $value): array
    {
        $recepcion = RecepcionUnidad::find($id);
        if (!$recepcion) {
            return ApiResponse::error('No se encontró el registro de recepción.');
        }

        $validacionDatos = $recepcion->validacion_datos ?? [
            'condicion_ingreso' => false,
            'placa' => false,
            'empresa_transporte' => false,
            'tipo_vehiculo' => false,
            'segunda_placa' => false,
            'conductor' => false,
        ];

        // 1. Modificar la tabla correspondiente según el campo
        switch ($field) {
            case 'condicion_ingreso':
                $recepcion->tipo_ingreso = $value;
                break;

            case 'placa':
                $vehiculo = null;
                if ($recepcion->id_vehiculo) {
                    $vehiculo = Vehiculo::find($recepcion->id_vehiculo);
                }

                if (!$vehiculo) {
                    // Si no tiene vehículo (caso ficticio sin asignar), creamos uno nuevo
                    $vehiculo = Vehiculo::create([
                        'estado' => 'Activo'
                    ]);
                    $recepcion->id_vehiculo = $vehiculo->id;
                }

                // Separar serie y número si viene en formato XXX-YYY
                if ($value && str_contains($value, '-')) {
                    $parts = explode('-', $value, 2);
                    $vehiculo->serie_placa = strtoupper(trim($parts[0]));
                    $vehiculo->numero_placa = strtoupper(trim($parts[1]));
                } else {
                    $vehiculo->serie_placa = null;
                    $vehiculo->numero_placa = strtoupper(trim($value));
                }
                $vehiculo->save();
                break;

            case 'empresa_transporte':
                $recepcion->id_empresa_transporte = $value ? (int)$value : null;
                break;

            case 'tipo_vehiculo':
                $recepcion->id_tipo_vehiculo = $value ? (int)$value : null;
                if ($recepcion->id_vehiculo) {
                    $vehiculo = Vehiculo::find($recepcion->id_vehiculo);
                    if ($vehiculo) {
                        $vehiculo->id_tipo_vehiculo = $value ? (int)$value : null;
                        $vehiculo->save();
                    }
                }
                break;

            case 'segunda_placa':
                $recepcion->segunda_placa = $value;
                break;

            case 'conductor':
                $recepcion->id_conductor = $value ? (int)$value : null;
                break;
        }

        // 2. Marcar la validación de este campo como completada (true)
        $validacionDatos[$field] = true;
        $recepcion->validacion_datos = $validacionDatos;
        $recepcion->save();

        $updated = RecepcionMineralData::get_recepcion_by_id_with_lotes($id);
        return ApiResponse::success($updated, 'Campo validado y actualizado correctamente.');
    }

    /**
     * Crear un lote vacío para una recepción de unidad
     */
    public static function crear_lote(int $id, int $idEmpleadoRegistro): array
    {
        $recepcion = RecepcionUnidad::find($id);
        if (!$recepcion) {
            return ApiResponse::error('No se encontró el registro de recepción.');
        }

        // Generar correlativo usando CorrelativoHelper
        $correlativoData = CorrelativoHelper::generar(
            tabla: 'lote_mineral',
            prefijo: 'LOT',
            filtros: [],
            longitudCeros: 5,
            reseteo: Periodo::Anual
        );

        $lote = LoteMineral::create([
            'id_recepcion_unidad' => $id,
            'id_empleado_registro' => $idEmpleadoRegistro,
            'correlativo' => $correlativoData['correlativo'],
            'numero_correlativo' => $correlativoData['numero_correlativo'],
            'created_at' => now()->toDateTimeString(),
        ]);

        $loteDetalle = RecepcionMineralData::get_lote_by_id($lote->id);
        return ApiResponse::success($loteDetalle, 'Lote generado correctamente.');
    }

    /**
     * Eliminar un lote vacío o incompleto
     */
    public static function eliminar_lote(int $loteId): array
    {
        $lote = LoteMineral::find($loteId);
        if (!$lote) {
            return ApiResponse::error('No se encontró el registro de lote.');
        }

        $lote->delete();
        return ApiResponse::success(null, 'Lote eliminado correctamente.');
    }

    /**
     * Registrar la información del peso inicial de un lote
     */
    public static function registrar_peso_inicial(int $loteId, array $data, array $archivos): array
    {
        $lote = LoteMineral::find($loteId);
        if (!$lote) {
            return ApiResponse::error('No se encontró el registro de lote.');
        }

        // Guardar los archivos de evidencias físicas en storage/app/public/lotes
        $evidenciasGuardadas = [];
        if (!empty($archivos)) {
            $evidenciasGuardadas = ArchivoHelper::guardarArchivos('lotes', $archivos);
        }

        $lote->id_proveedor_minero = $data['id_proveedor_minero'] ? (int)$data['id_proveedor_minero'] : null;
        $lote->id_encargado_muestra = $data['id_encargado_muestra'] ? (int)$data['id_encargado_muestra'] : null;
        $lote->id_zona_origen = $data['id_zona_origen'] ? (int)$data['id_zona_origen'] : null;
        $lote->numero_contacto = $data['numero_contacto'];
        $lote->tipo_carga = $data['tipo_carga'];
        $lote->tipo_producto = $data['tipo_producto'];
        $lote->tipo_mineral = $data['tipo_mineral'];
        $lote->peso_inicial = (float)$data['peso_inicial'];
        $lote->observacion_peso_inicial = $data['observacion_peso_inicial'] ?? null;
        $lote->fecha_hora_peso_inicial = now()->toDateTimeString();
        $lote->evidencias = $evidenciasGuardadas;

        // Heredar campos de la recepción de unidad asociada
        $recepcion = DB::table('recepcion_unidad')->where('id', $lote->id_recepcion_unidad)->first();
        if ($recepcion) {
            $lote->id_vehiculo = $recepcion->id_vehiculo;
            $lote->id_empresa_transporte = $recepcion->id_empresa_transporte;
            $lote->id_tipo_vehiculo = $recepcion->id_tipo_vehiculo;
            $lote->id_conductor = $recepcion->id_conductor;
        }

        $lote->save();

        $updatedLote = RecepcionMineralData::get_lote_by_id($loteId);
        return ApiResponse::success($updatedLote, 'Peso inicial registrado correctamente.');
    }

    /**
     * Registrar la información del peso final de un lote
     */
    public static function registrar_peso_final(int $loteId, array $data, array $archivos): array
    {
        $lote = LoteMineral::find($loteId);
        if (!$lote) {
            return ApiResponse::error('No se encontró el registro de lote.');
        }

        if ($lote->peso_inicial === null && !isset($data['peso_inicial'])) {
            return ApiResponse::error('Debe registrar primero el peso inicial del lote.');
        }

        // Guardar los archivos de evidencias físicas y anexarlos a los existentes
        $evidenciasGuardadas = [];
        if (array_key_exists('evidencias_existentes', $data)) {
            $evidenciasGuardadas = is_array($data['evidencias_existentes'])
                ? $data['evidencias_existentes']
                : (json_decode($data['evidencias_existentes'], true) ?? []);
        } else {
            $evidenciasGuardadas = $lote->evidencias ?? [];
        }

        if (!empty($archivos)) {
            $nuevosArchivos = ArchivoHelper::guardarArchivos('lotes', $archivos);
            $evidenciasGuardadas = array_merge($evidenciasGuardadas, $nuevosArchivos);
        }

        // Actualizar datos del peso inicial si fueron provistos
        if (array_key_exists('id_proveedor_minero', $data)) {
            $lote->id_proveedor_minero = $data['id_proveedor_minero'] ? (int)$data['id_proveedor_minero'] : null;
        }
        if (array_key_exists('id_encargado_muestra', $data)) {
            $lote->id_encargado_muestra = $data['id_encargado_muestra'] ? (int)$data['id_encargado_muestra'] : null;
        }
        if (array_key_exists('id_zona_origen', $data)) {
            $lote->id_zona_origen = $data['id_zona_origen'] ? (int)$data['id_zona_origen'] : null;
        }
        if (array_key_exists('numero_contacto', $data)) {
            $lote->numero_contacto = $data['numero_contacto'];
        }
        if (array_key_exists('tipo_carga', $data)) {
            $lote->tipo_carga = $data['tipo_carga'];
        }
        if (array_key_exists('tipo_producto', $data)) {
            $lote->tipo_producto = $data['tipo_producto'];
        }
        if (array_key_exists('tipo_mineral', $data)) {
            $lote->tipo_mineral = $data['tipo_mineral'];
        }
        if (array_key_exists('peso_inicial', $data) && $data['peso_inicial'] !== null) {
            $lote->peso_inicial = (float)$data['peso_inicial'];
        }
        if (array_key_exists('observacion_peso_inicial', $data)) {
            $lote->observacion_peso_inicial = $data['observacion_peso_inicial'];
        }
        if (array_key_exists('id_vehiculo', $data)) {
            $lote->id_vehiculo = $data['id_vehiculo'] ? (int)$data['id_vehiculo'] : null;
        }
        if (array_key_exists('id_empresa_transporte', $data)) {
            $lote->id_empresa_transporte = $data['id_empresa_transporte'] ? (int)$data['id_empresa_transporte'] : null;
        }
        if (array_key_exists('id_tipo_vehiculo', $data)) {
            $lote->id_tipo_vehiculo = $data['id_tipo_vehiculo'] ? (int)$data['id_tipo_vehiculo'] : null;
        }
        if (array_key_exists('id_conductor', $data)) {
            $lote->id_conductor = $data['id_conductor'] ? (int)$data['id_conductor'] : null;
        }

        $pesoFinal = (float)$data['peso_final'];
        $pesoInicial = (float)$lote->peso_inicial;

        $lote->peso_final = $pesoFinal;
        $lote->observacion_peso_final = $data['observacion_peso_final'] ?? null;
        $lote->fecha_hora_peso_final = now()->toDateTimeString();
        $lote->peso_neto = $pesoInicial - $pesoFinal; // Peso Inicial - Peso Final
        $lote->evidencias = $evidenciasGuardadas;
        $lote->save();

        $updatedLote = RecepcionMineralData::get_lote_by_id($loteId);
        return ApiResponse::success($updatedLote, 'Peso final registrado correctamente.');
    }

    /**
     * Cerrar el proceso de balanza de una recepción
     */
    public static function cerrar_proceso(int $id): array
    {
        $recepcion = RecepcionUnidad::find($id);
        if (!$recepcion) {
            return ApiResponse::error('No se encontró el registro de recepción.');
        }

        // Validaciones: 
        // 1. Que todos los checks de validacion_datos sean true
        $validacionDatos = $recepcion->validacion_datos;
        if (empty($validacionDatos)) {
            return ApiResponse::error('No se han validado los datos de vigilancia.');
        }
        foreach ($validacionDatos as $key => $val) {
            if (!$val) {
                return ApiResponse::error("Falta validar el campo: {$key}.");
            }
        }

        // 2. Que tenga al menos un lote y que todos los lotes tengan peso_final registrado
        $lotes = LoteMineral::where('id_recepcion_unidad', $id)->get();
        if ($lotes->isEmpty()) {
            return ApiResponse::error('Debe registrar al menos un lote de mineral para esta unidad.');
        }
        foreach ($lotes as $lote) {
            if ($lote->peso_final === null) {
                return ApiResponse::error("El lote {$lote->correlativo} no tiene registrado su peso final.");
            }
        }

        $recepcion->estado_pesaje = 'Pesado';
        $recepcion->fecha_hora_final_pesaje = now()->toDateTimeString();
        $recepcion->save();

        return ApiResponse::success(null, 'Proceso de balanza cerrado correctamente.');
    }

    /**
     * Registrar una unidad ficticia
     */
    public static function crear_unidad_ficticia(array $data): array
    {
        // 1. Crear un vehículo ficticio en la BD
        $uniqueId = rand(1000, 9999);
        $plateNum = date('ymd') . $uniqueId;
        $vehiculo = Vehiculo::create([
            'serie_placa' => 'FICT',
            'numero_placa' => $plateNum,
            'estado' => 'Activo'
        ]);

        // 2. Crear el registro de recepcion_unidad vacío
        $recepcion = RecepcionUnidad::create([
            'id_empleado_registro' => $data['id_empleado_registro'],
            'id_vehiculo' => $vehiculo->id,
            'id_empresa_transporte' => null,
            'id_tipo_vehiculo' => null,
            'id_conductor' => null,
            'tipo_ingreso' => 'Ficticio',
            'tipo_carga' => 'Mixto',
            'segunda_placa' => null,
            'fecha_hora_ingreso' => now()->toDateTimeString(),
            'estado' => 'En Planta',
            'estado_pesaje' => 'Sin Pesar',
            'id_surcusal' => $data['id_sucursal'],
            'validacion_datos' => [
                'condicion_ingreso' => false,
                'placa' => false,
                'empresa_transporte' => false,
                'tipo_vehiculo' => false,
                'segunda_placa' => false,
                'conductor' => false,
            ]
        ]);

        $nuevaRecepcion = RecepcionMineralData::get_recepcion_by_id_with_lotes($recepcion->id);
        return ApiResponse::success($nuevaRecepcion, 'Unidad ficticia creada correctamente.');
    }

    /**
     * Obtener el resumen de balanza filtrado
     */
    public static function get_resumen_balanza(array $filters): array
    {
        if (empty($filters['id_sucursal'])) {
            return ApiResponse::error('Debe seleccionar una sucursal.');
        }

        $data = RecepcionMineralData::get_resumen_balanza($filters);
        return ApiResponse::success($data, 'Resumen de balanza obtenido correctamente.');
    }

    /**
     * Actualizar toda la información de un lote de mineral (para Resumen de Balanza)
     */
    public static function actualizar_lote(int $loteId, array $data, array $archivos): array
    {
        $lote = LoteMineral::find($loteId);
        if (!$lote) {
            return ApiResponse::error('No se encontró el registro de lote.');
        }

        // Evidencias: manejar existentes y agregar nuevas
        $evidenciasGuardadas = [];
        if (array_key_exists('evidencias_existentes', $data)) {
            $evidenciasGuardadas = is_array($data['evidencias_existentes'])
                ? $data['evidencias_existentes']
                : (json_decode($data['evidencias_existentes'], true) ?? []);
        } else {
            $evidenciasGuardadas = $lote->evidencias ?? [];
        }

        if (!empty($archivos)) {
            $nuevosArchivos = ArchivoHelper::guardarArchivos('lotes', $archivos);
            $evidenciasGuardadas = array_merge($evidenciasGuardadas, $nuevosArchivos);
        }

        // Actualizar datos del lote
        $lote->id_proveedor_minero = $data['id_proveedor_minero'] ? (int)$data['id_proveedor_minero'] : null;
        $lote->id_encargado_muestra = $data['id_encargado_muestra'] ? (int)$data['id_encargado_muestra'] : null;
        $lote->id_zona_origen = $data['id_zona_origen'] ? (int)$data['id_zona_origen'] : null;
        $lote->numero_contacto = $data['numero_contacto'];
        $lote->tipo_carga = $data['tipo_carga'];
        $lote->tipo_producto = $data['tipo_producto'];
        $lote->tipo_mineral = $data['tipo_mineral'];
        $lote->peso_inicial = $data['peso_inicial'] !== null ? (float)$data['peso_inicial'] : null;
        $lote->observacion_peso_inicial = $data['observacion_peso_inicial'] ?? null;
        
        $lote->peso_final = $data['peso_final'] !== null ? (float)$data['peso_final'] : null;
        $lote->observacion_peso_final = $data['observacion_peso_final'] ?? null;

        // Calcular peso neto si ambos pesos existen
        if ($lote->peso_inicial !== null && $lote->peso_final !== null) {
            $lote->peso_neto = $lote->peso_inicial - $lote->peso_final;
        } else {
            $lote->peso_neto = null;
        }

        // Vehículo, conductor y transporte
        $lote->id_vehiculo = $data['id_vehiculo'] ? (int)$data['id_vehiculo'] : null;
        $lote->id_empresa_transporte = $data['id_empresa_transporte'] ? (int)$data['id_empresa_transporte'] : null;
        $lote->id_conductor = $data['id_conductor'] ? (int)$data['id_conductor'] : null;

        // Heredar el id_tipo_vehiculo del vehículo seleccionado
        if ($lote->id_vehiculo) {
            $vehiculo = Vehiculo::find($lote->id_vehiculo);
            if ($vehiculo) {
                $lote->id_tipo_vehiculo = $vehiculo->id_tipo_vehiculo;
            } else {
                $lote->id_tipo_vehiculo = null;
            }
        } else {
            $lote->id_tipo_vehiculo = null;
        }

        $lote->evidencias = $evidenciasGuardadas;
        $lote->save();

        $updatedLote = RecepcionMineralData::get_lote_by_id($loteId);
        return ApiResponse::success($updatedLote, 'Lote actualizado correctamente.');
    }

    /**
     * Obtener los metadatos para los filtros de resumen de balanza
     */
    public static function get_resumen_filtros(int $idSucursal): array
    {
        $data = RecepcionMineralData::get_resumen_filtros($idSucursal);
        return ApiResponse::success($data, 'Metadatos de filtros obtenidos correctamente.');
    }
}
