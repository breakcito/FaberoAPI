<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Menu extends Model
{
    protected $table = 'menu';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'path',
        'numero_orden', // va de 10 en 10, y ayuda a order los registro segun su flujo/importancia
        'es_desplegable', // true => es contenedor (solo agrupa submenus); false => hoja navegable
        'estado',
    ];

    protected $casts = [
        'es_desplegable' => 'boolean',
        'numero_orden'   => 'integer',
    ];

    /** Submenus que cuelgan de este menu. */
    public function submenus(): HasMany
    {
        return $this->hasMany(Submenu::class, 'id_menu');
    }

    /** Permisos otorgados directamente a este menu (solo menus hoja). */
    public function permisos(): HasMany
    {
        return $this->hasMany(ModuloRol::class, 'id_menu');
    }
}
