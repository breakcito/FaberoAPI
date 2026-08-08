<?php

namespace App\Modules\RecepcionVisitas\Services;

use App\Models\RecepcionVisita;
use App\Models\Visitante;
use App\Modules\RecepcionVisitas\Data\RecepcionVisitasData;
use App\Shared\Enums\_Generic\EstadoVisita;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Responses\ApiResponse;
use Illuminate\Support\Facades\DB;

class RecepcionVisitasService
{
    /**
     * Obtener listado de recepciones de visitas.
     */
    public static function get_recepciones(array $filters): array
    {
        $data = RecepcionVisitasData::get_recepciones($filters);

        return ApiResponse::success($data, 'Recepciones de visitas obtenidas correctamente');
    }

    /**
     * Registrar una recepción de visita y sus detalles de visitantes.
     * Crea cabeceras independientes en `recepcion_visita` para visitantes peatonales
     * y para cada vehículo acompañante registrado.
     */
    public static function crear_recepcion(array $data, array $visitantes, array $archivos, array $vehiculos = [], array $archivosVehiculos = [], array $evidencias = []): array
    {
        $lastId = null;

        try {
            DB::beginTransaction();

            $evidenciasJson = null;
            if (! empty($evidencias)) {
                $uploaded = ArchivoHelper::guardarArchivos('visitas/evidencias', $evidencias);
                if (! empty($uploaded)) {
                    $urls = array_map(fn ($file) => $file['url'], $uploaded);
                    $evidenciasJson = json_encode($urls);
                }
            }

            // Agrupar visitantes por vehículo acompañante (temp_id) vs peatonales
            $peatonales = [];
            $vehiculosVisitantesMap = [];

            foreach ($visitantes as $origIndex => $v) {
                $v['_orig_index'] = $origIndex;
                $vehId = $v['id_visita_vehiculo'] ?? null;
                if (! empty($vehId)) {
                    $key = (string) $vehId;
                    $vehiculosVisitantesMap[$key][] = $v;
                } else {
                    $peatonales[] = $v;
                }
            }

            // 1. Crear cabecera y detalles para Visitantes Peatonales / Individuales
            if (! empty($peatonales) || empty($vehiculos)) {
                $dataPeatonal = $data;
                $dataPeatonal['con_vehiculo'] = false;
                $dataPeatonal['serie_placa'] = null;
                $dataPeatonal['numero_placa'] = null;
                if ($evidenciasJson) {
                    $dataPeatonal['evidencias_ingreso'] = $evidenciasJson;
                }

                $idPeatonal = RecepcionVisitasData::crear_recepcion($dataPeatonal);
                $lastId = $idPeatonal;

                foreach ($peatonales as $v) {
                    $index = $v['_orig_index'];
                    $nombreVal = trim($v['nombre'] ?? '');
                    $dniVal = trim($v['dni'] ?? '');

                    if ($nombreVal === '' && $dniVal === '' && empty($v['id_visitante'])) {
                        continue;
                    }
                    if ($nombreVal === '') {
                        $nombreVal = 'VISITANTE';
                    }

                    $idVisitante = null;
                    if (! empty($v['id_visitante'])) {
                        $idVisitante = (int) $v['id_visitante'];
                        Visitante::whereKey($idVisitante)->update([
                            'nombre' => $nombreVal,
                            'apellido' => $v['apellido'] ?? '',
                            'telefono' => $v['telefono'] ?? null,
                        ]);
                    } else {
                        $idVisitante = self::obtenerOCrearVisitante(
                            $nombreVal,
                            $v['apellido'] ?? '',
                            $dniVal ?: null,
                            $v['telefono'] ?? null
                        );
                    }

                    $urlFoto = null;
                    if (isset($archivos[$index])) {
                        $uploaded = ArchivoHelper::guardarArchivos('visitas', $archivos[$index]);
                        if (! empty($uploaded)) {
                            $urls = array_map(fn ($file) => $file['url'], $uploaded);
                            $urlFoto = json_encode($urls);
                        }
                    }

                    \App\Models\RecepcionVisitaDetalle::create([
                        'id_recepcion_visita' => $idPeatonal,
                        'id_visitante' => $idVisitante,
                        'id_visita_vehiculo' => null,
                        'es_conductor' => 0,
                        'url_foto_documento' => $urlFoto,
                        'estado' => EstadoVisita::EnPlanta->value,
                    ]);
                }
            }

            // 2. Crear cabecera y detalles por cada Vehículo Acompañante
            if (! empty($vehiculos)) {
                foreach ($vehiculos as $vehIdx => $veh) {
                    $placa = $veh['placa'] ?? '';
                    $cant = (int) ($veh['cantidad_personas'] ?? 1);
                    $tempId = (string) ($veh['id'] ?? $veh['temp_id'] ?? '');

                    $vList = $vehiculosVisitantesMap[$tempId] ?? [];

                    $dataVeh = $data;
                    $dataVeh['con_vehiculo'] = true;
                    $dataVeh['serie_placa'] = null;
                    $dataVeh['numero_placa'] = $placa;
                    if ($evidenciasJson) {
                        $dataVeh['evidencias_ingreso'] = $evidenciasJson;
                    }

                    $idVehicular = RecepcionVisitasData::crear_recepcion($dataVeh);
                    $lastId = $idVehicular;

                    $urlFotoVeh = null;
                    if (isset($archivosVehiculos[$vehIdx]) && ! empty($archivosVehiculos[$vehIdx])) {
                        $guardadosVeh = ArchivoHelper::guardarArchivos('visitas', $archivosVehiculos[$vehIdx]);
                        if (! empty($guardadosVeh)) {
                            $urls = array_map(fn ($f) => $f['url'], $guardadosVeh);
                            $urlFotoVeh = json_encode($urls);
                        }
                    }

                    $realVehId = DB::table('visita_vehiculo')->insertGetId([
                        'id_recepcion_visita' => $idVehicular,
                        'placa' => $placa,
                        'cantidad_personas' => count($vList) > 0 ? count($vList) : $cant,
                        'url_foto' => $urlFotoVeh,
                        'created_at' => now()->toDateTimeString(),
                    ]);

                    foreach ($vList as $v) {
                        $index = $v['_orig_index'];
                        $nombreVal = trim($v['nombre'] ?? '');
                        $dniVal = trim($v['dni'] ?? '');

                        if ($nombreVal === '' && $dniVal === '' && empty($v['id_visitante'])) {
                            continue;
                        }
                        if ($nombreVal === '') {
                            $nombreVal = 'VISITANTE';
                        }

                        $idVisitante = null;
                        if (! empty($v['id_visitante'])) {
                            $idVisitante = (int) $v['id_visitante'];
                            Visitante::whereKey($idVisitante)->update([
                                'nombre' => $nombreVal,
                                'apellido' => $v['apellido'] ?? '',
                                'telefono' => $v['telefono'] ?? null,
                            ]);
                        } else {
                            $idVisitante = self::obtenerOCrearVisitante(
                                $nombreVal,
                                $v['apellido'] ?? '',
                                $dniVal ?: null,
                                $v['telefono'] ?? null
                            );
                        }

                        $urlFoto = null;
                        if (isset($archivos[$index])) {
                            $uploaded = ArchivoHelper::guardarArchivos('visitas', $archivos[$index]);
                            if (! empty($uploaded)) {
                                $urls = array_map(fn ($file) => $file['url'], $uploaded);
                                $urlFoto = json_encode($urls);
                            }
                        }

                        $rawEsConductor = $v['es_conductor'] ?? null;
                        $esConductor = ($rawEsConductor === true || $rawEsConductor === 1 || $rawEsConductor === '1' || $rawEsConductor === 'true') ? 1 : 0;

                        \App\Models\RecepcionVisitaDetalle::create([
                            'id_recepcion_visita' => $idVehicular,
                            'id_visitante' => $idVisitante,
                            'id_visita_vehiculo' => $realVehId,
                            'es_conductor' => $esConductor,
                            'url_foto_documento' => $urlFoto,
                            'estado' => EstadoVisita::EnPlanta->value,
                        ]);
                    }
                }
            }

            DB::commit();

            $nuevaRecepcion = RecepcionVisitasData::get_recepcion_by_id($lastId);

            return ApiResponse::success($nuevaRecepcion, 'Recepción de visita registrada correctamente');

        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al registrar la recepción de visita: '.$e->getMessage());
        }
    }

    /**
     * Registrar la salida de una visita.
     */
    public static function registrar_salida(int $idDetalle, ?string $observacionSalida, array $evidencias = []): array
    {
        $detalle = \App\Models\RecepcionVisitaDetalle::find($idDetalle);
        if (! $detalle) {
            return ApiResponse::error('No se encontró el detalle de la recepción de visita.');
        }

        $nowStr = now()->toDateTimeString();

        $urlEvidencias = null;
        if (! empty($evidencias)) {
            $uploaded = ArchivoHelper::guardarArchivos('visitas/evidencias_salida', $evidencias);
            if (! empty($uploaded)) {
                $urls = array_map(fn ($f) => $f['url'], $uploaded);
                $urlEvidencias = json_encode($urls);
            }
        }

        $detalle->observacion_salida = $observacionSalida;
        $detalle->fecha_hora_salida = $nowStr;
        if ($urlEvidencias) {
            $detalle->evidencias_salida = $urlEvidencias;
        }
        $detalle->estado = EstadoVisita::FueraDePlanta->value;
        $detalle->save();

        if ($detalle->id_recepcion_visita) {
            $visitaHeader = RecepcionVisita::find($detalle->id_recepcion_visita);
            if ($visitaHeader) {
                $visitaHeader->fecha_hora_salida = $nowStr;
                $visitaHeader->observacion_salida = $observacionSalida;
                if ($urlEvidencias) {
                    $visitaHeader->evidencias_salida = $urlEvidencias;
                }
                $visitaHeader->estado = EstadoVisita::FueraDePlanta->value;
                $visitaHeader->save();
            }
        }

        $updated = RecepcionVisitasData::get_recepcion_by_id($detalle->id_recepcion_visita);

        return ApiResponse::success($updated, 'Salida de visita registrada correctamente');
    }

    /**
     * Crear la visita (cabecera + detalle) asociada a una programación de unidad.
     * Separa las cabeceras en `recepcion_visita` para los visitantes de la unidad principal
     * y para cada vehículo acompañante externo.
     */
    public static function crear_recepcion_para_programacion(
        int $idEmpleadoRegistro,
        int $idRecepcionUnidad,
        int $idMotivoIngreso,
        ?string $observacion,
        array $visitantes,
        array $archivosPorIndice = [],
        array $vehiculos = [],
        array $archivosVehiculos = [],
        array $evidencias = []
    ): array {
        try {
            return DB::transaction(function () use ($idEmpleadoRegistro, $idRecepcionUnidad, $idMotivoIngreso, $observacion, $visitantes, $archivosPorIndice, $vehiculos, $archivosVehiculos, $evidencias) {
                $recepcionUnidad = DB::table('recepcion_unidad')->where('id', $idRecepcionUnidad)->first();
                $idEmpleadoAutoriza = $recepcionUnidad->id_empleado_autoriza ?? null;

                if (! empty($evidencias)) {
                    $uploadedEvidencias = ArchivoHelper::guardarArchivos('evidencias', $evidencias);
                    if (! empty($uploadedEvidencias)) {
                        $urlsEvidencias = array_map(fn ($f) => $f['url'], $uploadedEvidencias);
                        DB::table('recepcion_unidad')->where('id', $idRecepcionUnidad)->update([
                            'evidencias' => json_encode($urlsEvidencias),
                        ]);
                    }
                }

                $motivoTarget = $idMotivoIngreso;
                if (empty($motivoTarget)) {
                    $motivoObj = DB::table('motivo_ingreso')->where('es_recepcion_unidad', 1)->orWhere('es_recepcion_unidad', true)->first();
                    $motivoTarget = $motivoObj ? (int) $motivoObj->id : 1;
                }

                $placaUnidad = DB::table('recepcion_unidad')
                    ->leftJoin('vehiculo', 'vehiculo.id', '=', 'recepcion_unidad.id_vehiculo')
                    ->where('recepcion_unidad.id', $idRecepcionUnidad)
                    ->value('vehiculo.placa');

                // Agrupar visitantes por vehículo acompañante (temp_id) vs acompañantes de la unidad principal
                $peatonalesUnidad = [];
                $vehiculosVisitantesMap = [];

                foreach ($visitantes as $origIndex => $v) {
                    $v['_orig_index'] = $origIndex;
                    $vehId = $v['id_visita_vehiculo'] ?? null;
                    if (! empty($vehId)) {
                        $key = (string) $vehId;
                        $vehiculosVisitantesMap[$key][] = $v;
                    } else {
                        $peatonalesUnidad[] = $v;
                    }
                }

                $lastId = null;

                // 1. Crear cabecera y detalles para Ocupantes/Acompañantes de la Unidad Principal
                if (! empty($peatonalesUnidad) || empty($vehiculos)) {
                    $hasPlaca = ! empty($placaUnidad);
                    $idRecepcionPrincipal = RecepcionVisitasData::crear_recepcion([
                        'id_empleado_registro' => $idEmpleadoRegistro,
                        'id_empleado_autoriza' => $idEmpleadoAutoriza,
                        'id_motivo_ingreso' => $motivoTarget,
                        'id_recepcion_unidad' => $idRecepcionUnidad,
                        'observacion' => $observacion,
                        'con_vehiculo' => $hasPlaca,
                        'serie_placa' => null,
                        'numero_placa' => $hasPlaca ? $placaUnidad : null,
                        'estado' => EstadoVisita::EnPlanta->value,
                    ]);
                    $lastId = $idRecepcionPrincipal;

                    $realVehIdUnidad = null;
                    if ($hasPlaca) {
                        $realVehIdUnidad = DB::table('visita_vehiculo')->insertGetId([
                            'id_recepcion_visita' => $idRecepcionPrincipal,
                            'placa' => $placaUnidad,
                            'cantidad_personas' => count($peatonalesUnidad) > 0 ? count($peatonalesUnidad) : 1,
                            'created_at' => now()->toDateTimeString(),
                        ]);
                    }

                    foreach ($peatonalesUnidad as $v) {
                        $index = $v['_orig_index'];
                        $dni = $v['dni'] ?? null;
                        $nombre = $v['nombre'] ?? 'VISITANTE';
                        $apellido = $v['apellido'] ?? '';
                        $telefono = $v['telefono'] ?? null;

                        $idVisitante = self::obtenerOCrearVisitante($nombre, $apellido, $dni, $telefono);

                        $urlFotoDoc = null;
                        if (isset($archivosPorIndice[$index]) && ! empty($archivosPorIndice[$index])) {
                            $guardados = ArchivoHelper::guardarArchivos('visitas', $archivosPorIndice[$index]);
                            if (! empty($guardados)) {
                                $urls = array_map(fn ($f) => $f['url'], $guardados);
                                $urlFotoDoc = json_encode($urls);
                            }
                        }

                        $rawEsConductor = $v['es_conductor'] ?? null;
                        $esConductor = ($rawEsConductor === true || $rawEsConductor === 1 || $rawEsConductor === '1' || $rawEsConductor === 'true') ? 1 : 0;

                        \App\Models\RecepcionVisitaDetalle::create([
                            'id_recepcion_visita' => $idRecepcionPrincipal,
                            'id_visitante' => $idVisitante,
                            'id_visita_vehiculo' => $realVehIdUnidad,
                            'es_conductor' => $esConductor,
                            'url_foto_documento' => $urlFotoDoc,
                            'estado' => EstadoVisita::EnPlanta->value,
                        ]);
                    }
                }

                // 2. Crear cabecera y detalles para cada Vehículo Acompañante Externo
                if (! empty($vehiculos)) {
                    foreach ($vehiculos as $vehIdx => $veh) {
                        $placa = $veh['placa'] ?? '';
                        $cant = (int) ($veh['cantidad_personas'] ?? 1);
                        $tempId = (string) ($veh['id'] ?? $veh['temp_id'] ?? '');

                        $vList = $vehiculosVisitantesMap[$tempId] ?? [];

                        $idRecepcionVehicular = RecepcionVisitasData::crear_recepcion([
                            'id_empleado_registro' => $idEmpleadoRegistro,
                            'id_empleado_autoriza' => $idEmpleadoAutoriza,
                            'id_motivo_ingreso' => $motivoTarget,
                            'id_recepcion_unidad' => $idRecepcionUnidad,
                            'observacion' => $observacion,
                            'con_vehiculo' => true,
                            'serie_placa' => null,
                            'numero_placa' => $placa,
                            'estado' => EstadoVisita::EnPlanta->value,
                        ]);
                        $lastId = $idRecepcionVehicular;

                        $urlFotoVeh = null;
                        if (isset($archivosVehiculos[$vehIdx]) && ! empty($archivosVehiculos[$vehIdx])) {
                            $guardadosVeh = ArchivoHelper::guardarArchivos('visitas', $archivosVehiculos[$vehIdx]);
                            if (! empty($guardadosVeh)) {
                                $urls = array_map(fn ($f) => $f['url'], $guardadosVeh);
                                $urlFotoVeh = json_encode($urls);
                            }
                        }

                        $realVehId = DB::table('visita_vehiculo')->insertGetId([
                            'id_recepcion_visita' => $idRecepcionVehicular,
                            'placa' => $placa,
                            'cantidad_personas' => count($vList) > 0 ? count($vList) : $cant,
                            'url_foto' => $urlFotoVeh,
                            'created_at' => now()->toDateTimeString(),
                        ]);

                        foreach ($vList as $v) {
                            $index = $v['_orig_index'];
                            $dni = $v['dni'] ?? null;
                            $nombre = $v['nombre'] ?? 'VISITANTE';
                            $apellido = $v['apellido'] ?? '';
                            $telefono = $v['telefono'] ?? null;

                            $idVisitante = self::obtenerOCrearVisitante($nombre, $apellido, $dni, $telefono);

                            $urlFotoDoc = null;
                            if (isset($archivosPorIndice[$index]) && ! empty($archivosPorIndice[$index])) {
                                $guardados = ArchivoHelper::guardarArchivos('visitas', $archivosPorIndice[$index]);
                                if (! empty($guardados)) {
                                    $urls = array_map(fn ($f) => $f['url'], $guardados);
                                    $urlFotoDoc = json_encode($urls);
                                }
                            }

                            $rawEsConductor = $v['es_conductor'] ?? null;
                            $esConductor = ($rawEsConductor === true || $rawEsConductor === 1 || $rawEsConductor === '1' || $rawEsConductor === 'true') ? 1 : 0;

                            \App\Models\RecepcionVisitaDetalle::create([
                                'id_recepcion_visita' => $idRecepcionVehicular,
                                'id_visitante' => $idVisitante,
                                'id_visita_vehiculo' => $realVehId,
                                'es_conductor' => $esConductor,
                                'url_foto_documento' => $urlFotoDoc,
                                'estado' => EstadoVisita::EnPlanta->value,
                            ]);
                        }
                    }
                }

                $nueva = RecepcionVisitasData::get_recepcion_by_id($lastId);

                return ApiResponse::success($nueva, 'Visita de programación registrada correctamente');
            });
        } catch (\Throwable $e) {
            return ApiResponse::error('Error al registrar la visita de la programación: '.$e->getMessage());
        }
    }

    /**
     * Helper: busca visitante por DNI o crea uno nuevo.
     */
    private static function obtenerOCrearVisitante(string $nombre, string $apellido, ?string $dni, ?string $telefono): int
    {
        if (! empty($dni)) {
            $existing = \App\Data\VisitanteData::buscar_por_dni($dni);
            if ($existing) {
                $id = (int) ($existing['id_visitante'] ?? $existing['id'] ?? 0);
                if ($id > 0) {
                    Visitante::whereKey($id)->update([
                        'nombre' => $nombre,
                        'apellido' => $apellido,
                        'telefono' => $telefono,
                    ]);

                    return $id;
                }
            }
        }

        return \App\Data\VisitanteData::crear_visitante([
            'nombre' => $nombre,
            'apellido' => $apellido,
            'dni' => $dni,
            'telefono' => $telefono,
        ]);
    }
}
