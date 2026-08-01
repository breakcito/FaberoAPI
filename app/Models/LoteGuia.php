<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'peso_actual',
        'log_cambios',
    ];

    protected $casts = [
        'id_guia_primer_tramo' => 'integer',
        'id_lote_mineral' => 'integer',
        'peso_bruto' => 'float',
        'tara' => 'float',
        'peso_neto' => 'float',
        'peso_actual' => 'float',
        'log_cambios' => 'array',
    ];

    public function loteMineral(): BelongsTo
    {
        return $this->belongsTo(LoteMineral::class, 'id_lote_mineral');
    }

    public function guiaPrimerTramo(): BelongsTo
    {
        return $this->belongsTo(GuiaPrimerTramo::class, 'id_guia_primer_tramo');
    }
}
