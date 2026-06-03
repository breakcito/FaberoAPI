<?php

namespace App\Modules\Empleados\Data;

use App\Models\Empleado;
use App\Models\Labor;
use App\Models\LaborEmpleado;
use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Support\Facades\DB;

class EmpleadosData
{
    /**
     * Listar empleados con su empresa y cargo
     */
    public static function get_empleados(?int $id_empresa = null, ?int $id_empleado = null)
    {
        $sql = '
        SELECT DISTINCT
            e.id AS id_empleado,
            e.id_empresa,
            COALESCE(emp_asoc.razon_social, "Sin empresa") AS empresa,
            e.id_cargo,
            car.nombre AS cargo,
            car.id_area,
            a.nombre AS area,
            e.nombre,
            e.apellido,
            e.dni,
            e.ruc,
            e.carnet_extranjeria,
            e.pasaporte,
            e.fecha_nacimiento,
            e.path_foto,
            e.estado
        FROM
            empleado e
        LEFT JOIN empresa emp_asoc ON emp_asoc.id = e.id_empresa
        INNER JOIN cargo car ON car.id = e.id_cargo
        INNER JOIN area a ON a.id = car.id_area
        WHERE e.es_contratista = 0
        ';

        $params = [];

        if ($id_empleado) {
            $sql .= ' AND e.id = :id_empleado';
            $params['id_empleado'] = $id_empleado;

            return DB::selectOne($sql, $params);
        }

        if ($id_empresa !== null) {
            $sql .= ' AND e.id_empresa = :id_empresa';
            $params['id_empresa'] = $id_empresa;
        }

        $sql .= ' ORDER BY e.apellido ASC, e.nombre ASC';

        return DB::select($sql, $params);
    }

    /**
     * Obtener empleado por ID
     */
    public static function get_empleado_by_id(int $id_empleado)
    {
        return self::get_empleados(id_empleado: $id_empleado);
    }

    /**
     * Crear un nuevo empleado
     */
    public static function crear_empleado(
        int $id_empresa,
        int $id_cargo,
        string $nombre,
        string $apellido,
        ?string $dni,
        ?string $ruc,
        ?string $carnet_extranjeria,
        ?string $pasaporte,
        ?string $fecha_nacimiento,
        ?string $path_foto
    ) {
        return Empleado::insertGetId([
            'id_empresa'         => $id_empresa,
            'id_cargo'           => $id_cargo,
            'nombre'             => $nombre,
            'apellido'           => $apellido,
            'dni'                => $dni,
            'ruc'                => $ruc,
            'carnet_extranjeria' => $carnet_extranjeria,
            'pasaporte'          => $pasaporte,
            'fecha_nacimiento'   => $fecha_nacimiento,
            'path_foto'          => $path_foto,
            'es_contratista'     => 0,
            'estado'             => EstadoBase::Activo->value,
        ]);
    }

    /**
     * Obtener empresas activas
     */
    public static function get_empresas()
    {
        return DB::select('
            SELECT id AS id_empresa, razon_social AS nombre
            FROM empresa
            ORDER BY razon_social ASC
        ');
    }

    /**
     * Verificar si ya existe un empleado con el mismo DNI
     */
    public static function existe_dni(string $dni): bool
    {
        return Empleado::where('dni', $dni)->exists();
    }

    /**
     * Obtener todas las áreas activas
     */
    public static function get_areas()
    {
        return DB::select('SELECT id AS id_area, nombre FROM area WHERE estado = "Activo" ORDER BY nombre ASC');
    }

    /**
     * Obtener todas las minas activas
     */
    public static function get_minas()
    {
        return DB::select('SELECT id AS id_mina, nombre FROM mina WHERE estado = "Activo" ORDER BY nombre ASC');
    }

    /**
     * Obtener cargos por área
     */
    public static function get_cargos_by_area(int $id_area)
    {
        return DB::select('
            SELECT id AS id_cargo, nombre
            FROM cargo
            WHERE id_area = :id_area AND estado = "Activo"
            ORDER BY nombre ASC
        ', ['id_area' => $id_area]);
    }

    /**
     * Actualizar la ruta de la foto de un empleado
     */
    public static function actualizar_foto(int $id_empleado, ?string $path_foto): bool
    {
        return (bool) Empleado::where('id', $id_empleado)->update(['path_foto' => $path_foto]);
    }
}
