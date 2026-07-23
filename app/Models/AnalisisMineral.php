<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalisisMineral extends Model
{
    protected $table = 'analisis_mineral';

    public $timestamps = false;

    protected $fillable = [
        'id_grupo_analisis_detalle',
        'id_lote_mineral',
        'id_empleado_registro',
        'uuid_fila',
        'ley',
        'esta_confirmada',
        'tipo_origen',
        'created_at',
        'log_cambios',
    ];

    protected $casts = [
        'ley' => 'float',
        'esta_confirmada' => 'boolean',
        'log_cambios' => 'array',
    ];

    public function grupo_analisis_detalle(): BelongsTo
    {
        return $this->belongsTo(GrupoAnalisisDetalle::class, 'id_grupo_analisis_detalle');
    }

    public function lote_mineral(): BelongsTo
    {
        return $this->belongsTo(LoteMineral::class, 'id_lote_mineral');
    }

    public function empleado_registro(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'id_empleado_registro');
    }
}
