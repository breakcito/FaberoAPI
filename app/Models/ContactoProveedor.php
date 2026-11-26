<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Contacto de un proveedor.
 *
 * La tabla solo tiene `created_at` (con valor por defecto de la base), por lo
 * que el modelo no gestiona timestamps.
 */
class ContactoProveedor extends Model
{
    protected $table = 'contacto_proveedor';

    public $timestamps = false;

    protected $fillable = [
        'id_proveedor',
        'id_empleado_registro',
        'nombre_completo',
        'dni',
        'telefono',
        'correo',
    ];
}
