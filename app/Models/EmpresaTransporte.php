<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo que representa las empresas de transporte.
 */
class EmpresaTransporte extends Model
{
    protected $table = 'empresa_transporte';

    public $timestamps = false;

    protected $fillable = [
        'tipo_entidad',
        'dni',
        'ruc',
        'razon_social',
        'direccion',
        'telefono',
        'correo',
        'estado',
    ];
}
