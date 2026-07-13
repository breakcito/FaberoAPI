<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrupoAnalisisDetalle extends Model
{
    protected $table = 'grupo_analisis_detalle';

    public $timestamps = false;

    protected $fillable = [
        'id_grupo_analisis',
        'id_analito',
        'para_valorizacion_oro',
        'para_valorizacion_plata',
        'para_valorizacion_humedad',
        'para_valorizacion_recuperacion',
    ];

    public function grupo_analisis(): BelongsTo
    {
        return $this->belongsTo(GrupoAnalisis::class, 'id_grupo_analisis');
    }

    public function analito(): BelongsTo
    {
        return $this->belongsTo(Analito::class, 'id_analito');
    }
}
