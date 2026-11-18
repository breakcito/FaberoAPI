<?php

namespace App\Modules\Empleados\Data;

use App\Models\Empleado;
use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Support\Facades\DB;

class EmpleadosData
{
    /**
     * Listar empleados con su empresa, área y cargo.
     *
     * El área es propia del empleado (`empleado.id_area`) y el cargo es opcional.
     * Cuando el empleado no tiene área propia se muestra la del cargo
     * (datos previos a la columna `id_area`).
     */
    public static function get_empleados(?int $id_empresa = null, ?int $id_empleado = null)
    {
        $sql = '
        SELECT DISTINCT
            e.id AS id_empleado,
            e.id_empresa,
            COALESCE(emp_asoc.razon_social, "Sin empresa") AS empresa,
            COALESCE(e.id_area, a_car.id) AS id_area,
            COALESCE(a_emp.nombre, a_car.nombre) AS area,
            e.id_cargo,
            car.nombre AS cargo,
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
        LEFT JOIN cargo car ON car.id = e.id_cargo
        LEFT JOIN area a_emp ON a_emp.id = e.id_area
        LEFT JOIN area a_car ON a_car.id = car.id_area
        WHERE 1=1
        ';

        $params = [];

        if ($id_empleado) {
            $sql .= ' AND e.id = :id_empleado';
            $params['id_empleado'] = $id_empleado;

            $row = DB::selectOne($sql, $params);

            return $row ? [$row] : [];
        }

        if ($id_empresa !== null) {
            $sql .= ' AND e.id_empresa = :id_empresa';
            $params['id_empresa'] = $id_empresa;
        }

        $sql .= ' ORDER BY e.apellido ASC, e.nombre ASC';

        return DB::select($sql, $params);
    }

    /**
     * Obtener un único empleado por ID.
     *
     * Devuelve el registro (objeto) o null. El listado usa `get_empleados()`,
     * que sí devuelve un arreglo.
     */
    public static function get_empleado_by_id(int $id_empleado): ?object
    {
        $filas = self::get_empleados(id_empleado: $id_empleado);

        return $filas[0] ?? null;
    }

    /**
     * Crear un nuevo empleado. Área y cargo son opcionales.
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
        ?string $path_foto = null
    ) {
        return Empleado::insertGetId([
            'id_empresa' => $id_empresa,
            'id_area' => $id_area,
            'id_cargo' => $id_cargo,
            'nombre' => $nombre,
            'apellido' => $apellido,
            'dni' => $dni,
            'ruc' => $ruc,
            'carnet_extranjeria' => $carnet_extranjeria,
            'pasaporte' => $pasaporte,
            'fecha_nacimiento' => $fecha_nacimiento,
            'path_foto' => $path_foto,
            'estado' => EstadoBase::Activo->value,
        ]);
    }

    /**
     * Actualizar los datos de un empleado. Área y cargo son opcionales.
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
        ?string $path_foto = null
    ): bool {
        return (bool) Empleado::where('id', $id_empleado)->update([
            'id_empresa' => $id_empresa,
            'id_area' => $id_area,
            'id_cargo' => $id_cargo,
            'nombre' => $nombre,
            'apellido' => $apellido,
            'dni' => $dni,
            'ruc' => $ruc,
            'carnet_extranjeria' => $carnet_extranjeria,
            'pasaporte' => $pasaporte,
            'fecha_nacimiento' => $fecha_nacimiento,
            'path_foto' => $path_foto,
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
     * Obtener el área a la que pertenece un cargo
     */
    public static function get_area_de_cargo(int $id_cargo): ?int
    {
        $fila = DB::selectOne('SELECT id_area FROM cargo WHERE id = :id_cargo', [
            'id_cargo' => $id_cargo,
        ]);

        return $fila ? (int) $fila->id_area : null;
    }

    /**
     * Actualizar la ruta de la foto de un empleado
     */
    public static function actualizar_foto(int $id_empleado, ?string $path_foto): bool
    {
        return (bool) Empleado::where('id', $id_empleado)->update(['path_foto' => $path_foto]);
    }
}
