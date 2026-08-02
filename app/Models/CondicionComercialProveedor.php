<?php

namespace App\Models;

use App\Shared\Enums\_Generic\ElementoQuimicoValorizacion;
use Illuminate\Database\Eloquent\Model;

class CondicionComercialProveedor extends Model
{
    protected $table = 'condicion_comercial_proveedor';

    public $timestamps = false;

    protected $fillable = [
        'id_proveedor_minero',
        'elemento_quimico',
        'ley_inicio',
        'ley_fin',
        'maquila',
        'recuperacion',
        'consumo',
        'riesgo_comercial',
        'estado',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'elemento_quimico' => ElementoQuimicoValorizacion::class,
        ];
    }
}
