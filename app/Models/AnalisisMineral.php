<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalisisMineral extends Model
{
    protected $table = 'analisis_mineral';

    public $timestamps = false;

    protected $fillable = [
        'id_grupo_analisis',
        'id_laboratorio',
        'asumido_por',
        'numero_ensayo',
        'documentos',
        'ley_oro',
        'ley_plata',
        'ley_humedad',
        'ley_recuperacion',
        'ley_oro_confirmada',
        'ley_plata_confirmada',
        'id_muestra_externa',
        'id_empleado_registro',
        'tipo_origen',
        'sin_lote',
        'created_at',
        'log_cambios',
    ];

    protected $casts = [
        'ley_oro' => 'float',
        'ley_plata' => 'float',
        'ley_humedad' => 'float',
        'ley_recuperacion' => 'float',
        'ley_oro_confirmada' => 'boolean',
        'ley_plata_confirmada' => 'boolean',
        'sin_lote' => 'boolean',
        'documentos' => 'array',
        'log_cambios' => 'array',
    ];

    public function grupo_analisis(): BelongsTo
    {
        return $this->belongsTo(GrupoAnalisis::class, 'id_grupo_analisis');
    }

    public function laboratorio(): BelongsTo
    {
        return $this->belongsTo(Laboratorio::class, 'id_laboratorio');
    }

    public function empleado_registro(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'id_empleado_registro');
    }
}
