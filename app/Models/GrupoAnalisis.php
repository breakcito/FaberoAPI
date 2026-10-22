<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GrupoAnalisis extends Model
{
    protected $table = 'grupo_analisis';

    public $timestamps = false;

    protected $fillable = [
        'id_empleado_registro',
        'id_lote_mineral',
        'id_muestra_externa',
        'nombre',
        'numero_correlativo',
        'ley_oro_cierre',
        'ley_plata_cierre',
        'ley_humedad_cierre',
        'ley_recuperacion_cierre',
        'log_cambios',
        'tiene_propuesta_oro',
        'tiene_propuesta_plata',
        'created_at',
    ];

    protected $casts = [
        'ley_oro_cierre' => 'float',
        'ley_plata_cierre' => 'float',
        'ley_humedad_cierre' => 'float',
        'ley_recuperacion_cierre' => 'float',
        'tiene_propuesta_oro' => 'boolean',
        'tiene_propuesta_plata' => 'boolean',
        'log_cambios' => 'array',
    ];

    public function empleado_registro(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'id_empleado_registro');
    }

    public function lote_mineral(): BelongsTo
    {
        return $this->belongsTo(LoteMineral::class, 'id_lote_mineral');
    }

    public function analisis(): HasMany
    {
        return $this->hasMany(AnalisisMineral::class, 'id_grupo_analisis');
    }
}
