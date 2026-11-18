<?php

namespace App\Modules\Empleados;

use App\Modules\Empleados\Data\EmpleadosData;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\UploadedFile;

class EmpleadosService
{
    /**
     * Listar empleados
     */
    public static function get_empleados(?int $id_empresa = null)
    {
        $empleados = EmpleadosData::get_empleados(id_empresa: $id_empresa);

        foreach ($empleados as $empleado) {
            if ($empleado->path_foto && ! str_starts_with($empleado->path_foto, 'http')) {
                $empleado->path_foto = asset('storage/'.$empleado->path_foto);
            }
        }

        return ApiResponse::success($empleados);
    }

    /**
     * Obtener empresas activas
     */
    public static function get_empresas()
    {
        return ApiResponse::success(EmpleadosData::get_empresas());
    }

    /**
     * Obtener todas las áreas activas
     */
    public static function get_areas()
    {
        return ApiResponse::success(EmpleadosData::get_areas());
    }

    /**
     * Obtener cargos por área
     */
    public static function get_cargos(int $id_area)
    {
        return ApiResponse::success(EmpleadosData::get_cargos_by_area($id_area));
    }

    /**
     * Registrar un nuevo empleado.
     *
     * Área y cargo son opcionales. Si se informa un cargo, el área del empleado
     * es el área de ese cargo (el cargo manda sobre el área enviada).
     */
    public static function crear_empleado(
        int $id_empresa,
        string $nombre,
        string $apellido,
        ?int $id_area = null,
        ?int $id_cargo = null,
        ?string $dni = null,
        ?string $ruc = null,
        ?string $carnet_extranjeria = null,
        ?string $pasaporte = null,
        ?string $fecha_nacimiento = null,
        ?UploadedFile $foto = null
    ) {
        if ($dni && EmpleadosData::existe_dni($dni)) {
            return ApiResponse::error('El DNI ingresado ya se encuentra registrado.');
        }

        $path_foto = null;
        if ($foto && $foto->isValid()) {
            $archivos = ArchivoHelper::guardarArchivos('fotos-empleados', [$foto]);
            if (! empty($archivos)) {
                $path_foto = asset('storage/'.$archivos[0]['path_relativo']);
            }
        }

        $id_area = self::resolver_area($id_area, $id_cargo);

        $id = EmpleadosData::crear_empleado(
            id_empresa: $id_empresa,
            nombre: $nombre,
            apellido: $apellido,
            id_area: $id_area,
            id_cargo: $id_cargo,
            dni: $dni,
            ruc: $ruc,
            carnet_extranjeria: $carnet_extranjeria,
            pasaporte: $pasaporte,
            fecha_nacimiento: $fecha_nacimiento,
            path_foto: $path_foto
        );

        $nuevoEmpleado = EmpleadosData::get_empleado_by_id($id);

        return ApiResponse::success(
            $nuevoEmpleado,
            'Empleado registrado correctamente'
        );
    }

    /**
     * Actualizar los datos de un empleado.
     *
     * Área y cargo son opcionales. Si se informa un cargo, el área del empleado
     * es el área de ese cargo (el cargo manda sobre el área enviada).
     */
    public static function actualizar_empleado(
        int $id_empleado,
        int $id_empresa,
        string $nombre,
        string $apellido,
        ?int $id_area = null,
        ?int $id_cargo = null,
        ?string $dni = null,
        ?string $ruc = null,
        ?string $carnet_extranjeria = null,
        ?string $pasaporte = null,
        ?string $fecha_nacimiento = null,
        ?UploadedFile $foto = null
    ) {
        $existente = EmpleadosData::get_empleado_by_id($id_empleado);
        if (! $existente) {
            return ApiResponse::error('El empleado no existe.');
        }

        if ($dni && $dni !== $existente->dni && EmpleadosData::existe_dni($dni)) {
            return ApiResponse::error('El DNI ingresado ya se encuentra registrado.');
        }

        $path_foto = null;
        if ($foto && $foto->isValid()) {
            $archivos = ArchivoHelper::guardarArchivos('fotos-empleados', [$foto]);
            if (! empty($archivos)) {
                $path_foto = asset('storage/'.$archivos[0]['path_relativo']);
            }
        }

        $id_area = self::resolver_area($id_area, $id_cargo);

        EmpleadosData::actualizar_empleado(
            id_empleado: $id_empleado,
            id_empresa: $id_empresa,
            nombre: $nombre,
            apellido: $apellido,
            id_area: $id_area,
            id_cargo: $id_cargo,
            dni: $dni,
            ruc: $ruc,
            carnet_extranjeria: $carnet_extranjeria,
            pasaporte: $pasaporte,
            fecha_nacimiento: $fecha_nacimiento,
            path_foto: $path_foto ?? $existente->path_foto
        );

        $empleado = EmpleadosData::get_empleado_by_id($id_empleado);

        return ApiResponse::success($empleado, 'Empleado actualizado correctamente');
    }

    /**
     * Resolver el área del empleado: si hay cargo, manda el área de ese cargo.
     */
    private static function resolver_area(?int $id_area, ?int $id_cargo): ?int
    {
        if ($id_cargo) {
            return EmpleadosData::get_area_de_cargo($id_cargo) ?? $id_area;
        }

        return $id_area;
    }

    /**
     * Actualizar la foto de un empleado
     */
    public static function actualizar_foto(int $id_empleado, ?UploadedFile $file)
    {
        $archivos = ArchivoHelper::guardarArchivos('fotos-empleados', [$file]);
        if (empty($archivos)) {
            return ApiResponse::error('No se pudo procesar la imagen.');
        }

        // Guardar URL completa en BD (no solo el path relativo)
        $path_foto = asset('storage/'.$archivos[0]['path_relativo']);
        EmpleadosData::actualizar_foto($id_empleado, $path_foto);

        $empleado = EmpleadosData::get_empleado_by_id($id_empleado);

        // path_foto ya viene como URL completa desde la BD
        return ApiResponse::success($empleado, 'Foto de perfil actualizada correctamente');
    }
}
