<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoteMineral extends Model
{
    protected $table = 'lote_mineral';

    public $timestamps = false;

    protected $fillable = [
        'id_recepcion_unidad',
        'id_proveedor_minero',
        'id_empleado_registro',
        'id_encargado_muestra',
        'id_zona_origen',
        'correlativo',
        'numero_correlativo',
        'tipo_carga',
        'numero_contacto',
        'tipo_producto',
        'tipo_mineral',
        'evidencias',
        'peso_inicial',
        'fecha_hora_peso_inicial',
        'observacion_peso_inicial',
        'peso_final',
        'fecha_hora_peso_final',
        'observacion_peso_final',
        'peso_neto',
        'id_vehiculo',
        'id_empresa_transporte',
        'id_tipo_vehiculo',
        'id_conductor',
        'condicion_ingreso',
        'log_cambios',
        'created_at',
    ];

    protected $casts = [
        'evidencias' => 'array',
        'peso_inicial' => 'float',
        'peso_final' => 'float',
        'peso_neto' => 'float',
        'log_cambios' => 'array',
    ];
}
