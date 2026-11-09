<?php

namespace App\Models;

use App\Shared\Enums\ContabilidadCompra\TipoNotaComprobante;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nota de crédito o débito emitida contra un comprobante de compra.
 *
 * Las notas NO alteran los montos reales del comprobante (`total`,
 * `monto_neto`, `monto_detraccion_soles`): el monto ajustado se persiste en
 * `comprobante_compra.monto_neto_final` / `monto_detraccion_soles_final`.
 */
class ComprobanteNota extends Model
{
    protected $table = 'comprobante_nota';

    public $timestamps = false;

    protected $fillable = [
        'id_comprobante_compra',
        'id_empleado_registro',
        'id_empleado_anulacion',
        'tipo',
        'fecha_emision',
        'codigo_nota',
        'motivo',
        'monto',
        'documentos',
        'es_anulado',
        'fecha_hora_anulacion',
        'motivo_anulacion',
        'created_at',
    ];

    protected $casts = [
        'id_comprobante_compra' => 'integer',
        'id_empleado_registro' => 'integer',
        'id_empleado_anulacion' => 'integer',
        'tipo' => TipoNotaComprobante::class,
        'fecha_emision' => 'date:Y-m-d',
        'monto' => 'float',
        'documentos' => 'array',
        'es_anulado' => 'boolean',
        'fecha_hora_anulacion' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(ComprobanteCompra::class, 'id_comprobante_compra');
    }

    public function empleadoRegistro(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'id_empleado_registro');
    }

    public function empleadoAnulacion(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'id_empleado_anulacion');
    }
}
