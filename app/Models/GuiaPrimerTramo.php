<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GuiaPrimerTramo extends Model
{
    protected $table = 'guia_primer_tramo';

    public $timestamps = false;

    protected $fillable = [
        'id_sucursal',
        'id_proveedor',
        'id_concesion',
        'id_conductor',
        'id_vehiculo',
        'id_empresa_transporte',
        'id_vehiculo_carreta',
        'id_empresa_transporte_carreta',
        'qr_token_transportista',
        'qr_token_remitente',
        'motivo_traslado',
        'evidencias',
        'fecha_inicio_traslado',
        'fecha_emision',
        'fecha_en_planta',
        'serie_guia_remitente',
        'numero_guia_remitente',
        'serie_guia_transportista',
        'numero_guia_transportista',
        'sin_guia_transportista',
        'created_at',
    ];

    protected $casts = [
        'evidencias' => 'array',
        'fecha_inicio_traslado' => 'datetime',
        'fecha_emision' => 'datetime',
        'fecha_en_planta' => 'datetime',
        'sin_guia_transportista' => 'boolean',
    ];
}
