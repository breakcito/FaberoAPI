<?php

namespace App\Modules\EmpresasTransporte\Data;

use App\Models\EmpresaTransporte;
use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Support\Facades\DB;

class EmpresasTransporteData
{
    public static function get_empresas_transporte(?int $id = null)
    {
        $sql = '
        SELECT
            et.id,
            et.tipo_entidad,
            et.dni,
            et.ruc,
            et.razon_social,
            et.direccion,
            et.telefono,
            et.correo,
            et.estado
        FROM
            empresa_transporte et
        WHERE 1 = 1
        ';

        $params = [];
        if ($id !== null) {
            $sql .= ' AND et.id = :id';
            $params['id'] = $id;

            return (array) DB::selectOne($sql, $params);
        }

        $sql .= ' ORDER BY et.razon_social ASC;';

        return DB::select($sql, $params);
    }

    public static function get_empresa_transporte_by_id(int $id): array
    {
        return self::get_empresas_transporte(id: $id);
    }

    public static function crear_empresa_transporte(
        string $tipoEntidad,
        ?string $dni,
        string $ruc,
        string $razonSocial,
        ?string $direccion,
        ?string $telefono,
        ?string $correo
    ): int {
        return EmpresaTransporte::insertGetId([
            'tipo_entidad' => $tipoEntidad,
            'dni' => $dni,
            'ruc' => $ruc,
            'razon_social' => $razonSocial,
            'direccion' => $direccion,
            'telefono' => $telefono,
            'correo' => $correo,
            'estado' => EstadoBase::Activo->value,
        ]);
    }

    public static function editar_empresa_transporte(
        int $id,
        string $tipoEntidad,
        ?string $dni,
        string $ruc,
        string $razonSocial,
        ?string $direccion,
        ?string $telefono,
        ?string $correo
    ): bool {
        return EmpresaTransporte::where('id', $id)->update([
            'tipo_entidad' => $tipoEntidad,
            'dni' => $dni,
            'ruc' => $ruc,
            'razon_social' => $razonSocial,
            'direccion' => $direccion,
            'telefono' => $telefono,
            'correo' => $correo,
        ]) >= 0;
    }

    public static function cambiar_estado_empresa_transporte(int $id, string $estado): bool
    {
        return EmpresaTransporte::where('id', $id)->update([
            'estado' => $estado,
        ]) >= 0;
    }
}
