<?php

namespace App\Models;

use App\Shared\Enums\_Generic\CodigoPermiso;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class Usuario extends Model implements AuthenticatableContract, JWTSubject
{
    use Authenticatable;

    protected $table = 'usuario';

    public $timestamps = false;

    protected $fillable = [
        'id_rol',
        'id_empleado',
        'username',
        'password',
        'estado',
        'permisos',
    ];

    protected $hidden = ['password'];

    // Obtener el identificador para JWT.
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    // Array con los valores personalizados para el JWT.
    public function getJWTCustomClaims(): array
    {
        return [];
    }

    public static function getInfoUsuarioById(int $id_usuario)
    {
        $sql = '
        SELECT
            usu.id as id_usuario,
            usu.id_rol,
            usu.id_empleado,
            emp.nombre,
            emp.apellido,
            emp.dni,
            emp.ruc,
            emp.carnet_extranjeria,
            emp.pasaporte,
            emp.fecha_nacimiento,
            emp.path_foto,
            emp.estado as estado_empleado,
            usu.estado as estado_usuario,
            usu.permisos
        FROM
            usuario usu
        INNER JOIN empleado emp on emp.id = usu.id_empleado
        WHERE
            usu.id = :id_usuario
        ';

        $info = DB::selectOne($sql, [
            'id_usuario' => $id_usuario,
        ]);

        if ($info) {
            $info->permisos = CodigoPermiso::decodificar($info->permisos);
        }

        return $info;
    }
}
