<?php

namespace App\Modules\PlantasDestino\Data;

use App\Models\PlantaDestino;
use App\Models\PlantaProveedor;
use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Support\Facades\DB;

class PlantasDestinoData
{
    /**
     * Listar plantas de destino con contadores de cuentas y proveedores activos
     */
    public static function get_plantas(?int $id_planta = null): array|object
    {
        $sql = '
        SELECT 
            pd.id,
            pd.ruc,
            pd.razon_social,
            pd.direccion,
            pd.telefono,
            pd.correo,
            pd.estado,
            (SELECT COUNT(*) FROM cuenta_bancaria_planta_destino cb WHERE cb.id_planta_destino = pd.id AND cb.estado = "Activo") as cantidad_cuentas,
            (SELECT COUNT(*) FROM planta_proveedor pp WHERE pp.id_planta = pd.id) as cantidad_proveedores
        FROM 
            planta_destino pd
        ';

        $params = [];

        if ($id_planta !== null) {
            $sql .= ' WHERE pd.id = :id_planta';
            $params['id_planta'] = $id_planta;

            return DB::selectOne($sql, $params) ?? (object) [];
        }

        $sql .= ' ORDER BY pd.razon_social ASC';

        return DB::select($sql, $params);
    }

    public static function get_planta_by_id(int $id_planta): array|object
    {
        return self::get_plantas($id_planta);
    }

    public static function existe_ruc(string $ruc, ?int $excluirId = null): bool
    {
        $query = PlantaDestino::where('ruc', $ruc);
        if ($excluirId !== null) {
            $query->where('id', '!=', $excluirId);
        }

        return $query->exists();
    }

    public static function crear_planta(
        string $ruc,
        string $razon_social,
        ?string $direccion,
        ?string $telefono,
        ?string $correo
    ): int {
        return PlantaDestino::insertGetId([
            'ruc' => $ruc,
            'razon_social' => $razon_social,
            'direccion' => $direccion,
            'telefono' => $telefono,
            'correo' => $correo,
            'estado' => EstadoBase::Activo->value,
        ]);
    }

    public static function editar_planta(
        int $id,
        string $ruc,
        string $razon_social,
        ?string $direccion,
        ?string $telefono,
        ?string $correo
    ): bool {
        return PlantaDestino::where('id', $id)->update([
            'ruc' => $ruc,
            'razon_social' => $razon_social,
            'direccion' => $direccion,
            'telefono' => $telefono,
            'correo' => $correo,
        ]) >= 0;
    }

    public static function cambiar_estado_planta(int $id, string $estado): bool
    {
        return PlantaDestino::where('id', $id)->update(['estado' => $estado]) >= 0;
    }

    /* --- Asociación de Proveedores --- */

    public static function get_proveedores_asociados(int $id_planta): array
    {
        return DB::select('
            SELECT 
                p.id AS id_proveedor,
                p.tipo_entidad,
                p.dni,
                p.ruc,
                p.razon_social,
                p.direccion,
                p.telefono,
                p.correo,
                p.estado
            FROM 
                proveedor p
            INNER JOIN planta_proveedor pp ON pp.id_proveedor = p.id
            WHERE 
                pp.id_planta = :id_planta
            ORDER BY p.razon_social ASC
        ', ['id_planta' => $id_planta]);
    }

    public static function existe_asociacion(int $id_planta, int $id_proveedor): bool
    {
        return PlantaProveedor::where('id_planta', $id_planta)
            ->where('id_proveedor', $id_proveedor)
            ->exists();
    }

    public static function asociar_proveedor(int $id_planta, int $id_proveedor): int
    {
        return PlantaProveedor::insertGetId([
            'id_planta' => $id_planta,
            'id_proveedor' => $id_proveedor,
        ]);
    }

    public static function desasociar_proveedor(int $id_planta, int $id_proveedor): bool
    {
        return PlantaProveedor::where('id_planta', $id_planta)
            ->where('id_proveedor', $id_proveedor)
            ->delete() > 0;
    }
}
