<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Submenu extends Model
{
    protected $table = 'submenu';

    public $timestamps = false;

    protected $fillable = [
        'id_menu',
        'nombre',
        'path',
        'numero_orden', // va de 10 en 10, y ayuda a order los registro segun su flujo/importancia
        'es_desplegable', // true => es contenedor (solo agrupa modulos); false => hoja navegable
        'estado',
    ];

    protected $casts = [
        'es_desplegable' => 'boolean',
        'numero_orden'   => 'integer',
    ];

    /** Modulos que cuelgan de este submenu. */
    public function modulos(): HasMany
    {
        return $this->hasMany(Modulo::class, 'id_submenu');
    }

    /** Permisos otorgados directamente a este submenu (solo submenus hoja). */
    public function permisos(): HasMany
    {
        return $this->hasMany(ModuloRol::class, 'id_submenu');
    }
}
