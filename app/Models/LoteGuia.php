<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoteGuia extends Model
{
    protected $table = 'lote_guia';

    public $timestamps = false;

    protected $fillable = [
        'id_guia_primer_tramo',
        'id_lote_mineral',
        'peso_bruto',
        'tara',
        'peso_neto',
        'log_cambios',
    ];

    protected $casts = [
        'peso_bruto' => 'float',
        'tara' => 'float',
        'peso_neto' => 'float',
        'log_cambios' => 'array',
    ];
}
