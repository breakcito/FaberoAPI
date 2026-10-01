<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DespachoDetalle extends Model
{
    protected $table = 'despacho_detalle';

    public $timestamps = false;

    protected $fillable = [
        'id_despacho',
        'id_blending',
        'id_lote_mineral',
        'peso_tomado',
        'peso_actual',
        'codigo_preliminar',
        'ley_oro_final',
        'ley_plata_final',
        'ley_oro_final_confirmada',
        'ley_plata_final_confirmada',
        'esta_valorizado_oro',
        'esta_valorizado_plata',
    ];

    protected $casts = [
        'id_despacho' => 'integer',
        'id_blending' => 'integer',
        'id_lote_mineral' => 'integer',
        'peso_tomado' => 'float',
        'peso_actual' => 'float',
        'ley_oro_final' => 'float',
        'ley_plata_final' => 'float',
        'ley_oro_final_confirmada' => 'boolean',
        'ley_plata_final_confirmada' => 'boolean',
        'esta_valorizado_oro' => 'boolean',
        'esta_valorizado_plata' => 'boolean',
    ];

    public function loteMineral(): BelongsTo
    {
        return $this->belongsTo(LoteMineral::class, 'id_lote_mineral');
    }

    public function blending(): BelongsTo
    {
        return $this->belongsTo(Blending::class, 'id_blending');
    }

    public function distribucionesDetalle(): HasMany
    {
        return $this->hasMany(DistribucionDetalle::class, 'id_despacho_detalle');
    }
}
