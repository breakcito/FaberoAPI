<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Modulo extends Model
{
    protected $table = 'modulo';

    public $timestamps = false;

    protected $fillable = [
        'id_submenu',
        'nombre',
        'path',
        'numero_orden', // va de 10 en 10, y ayuda a order los registro segun su flujo/importancia
        'es_desplegable', // los modulos son hojas; se mantiene por uniformidad del arbol
        'estado',
    ];

    protected $casts = [
        'es_desplegable' => 'boolean',
        'numero_orden'   => 'integer',
    ];

    /** Permisos otorgados a este modulo. */
    public function permisos(): HasMany
    {
        return $this->hasMany(ModuloRol::class, 'id_modulo');
    }
}
