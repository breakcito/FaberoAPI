<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlendingDetalle extends Model
{
    protected $table = 'blending_detalle';

    public $timestamps = false;

    protected $fillable = [
        'id_blending',
        'id_lote_guia',
        'id_reblending',
        'peso_actual',
        'peso_tomado',
        'created_at',
    ];

    protected $casts = [
        'id_blending' => 'integer',
        'id_lote_guia' => 'integer',
        'id_reblending' => 'integer',
        'peso_actual' => 'float',
        'peso_tomado' => 'float',
        'created_at' => 'datetime',
    ];

    public function blending(): BelongsTo
    {
        return $this->belongsTo(Blending::class, 'id_blending');
    }

    public function loteGuia(): BelongsTo
    {
        return $this->belongsTo(LoteGuia::class, 'id_lote_guia');
    }

    public function reblending(): BelongsTo
    {
        return $this->belongsTo(Blending::class, 'id_reblending');
    }
}
