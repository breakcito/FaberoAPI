<?php

namespace App\Models;

use App\Shared\Enums\ValorizacionVenta\EstadoValorizacionVenta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ValorizacionVenta extends Model
{
    protected $table = 'valorizacion_venta';

    public $timestamps = false;

    protected $fillable = [
        'id_planta',
        'id_empleado_registro',
        'id_empleado_aprobacion',
        'id_empleado_anulacion',
        'numero_correlativo',
        'correlativo',
        'fecha_hora_valorizacion',
        'fecha_hora_aprobacion',
        'fecha_hora_anulacion',
        'motivo_anulacion',
        'evidencias_anulacion',
        'codigo',
        'evidencias',
        'monto_penalidad',
        'monto_flete',
        'log_cambios',
        'created_at',
        'estado',
    ];

    protected $casts = [
        'id_planta' => 'integer',
        'id_empleado_registro' => 'integer',
        'id_empleado_aprobacion' => 'integer',
        'id_empleado_anulacion' => 'integer',
        'numero_correlativo' => 'integer',
        'evidencias' => 'array',
        'evidencias_anulacion' => 'array',
        'log_cambios' => 'array',
        'fecha_hora_valorizacion' => 'datetime',
        'fecha_hora_aprobacion' => 'datetime',
        'fecha_hora_anulacion' => 'datetime',
        'monto_penalidad' => 'float',
        'monto_flete' => 'float',
        'created_at' => 'datetime',
        'estado' => EstadoValorizacionVenta::class,
    ];

    public function planta(): BelongsTo
    {
        return $this->belongsTo(PlantaDestino::class, 'id_planta');
    }

    public function empleadoRegistro(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'id_empleado_registro');
    }

    public function empleadoAprobacion(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'id_empleado_aprobacion');
    }

    public function empleadoAnulacion(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'id_empleado_anulacion');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(ValorizacionVentaDetalle::class, 'id_valorizacion_venta');
    }
}
