<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proveedor asociado a una recepción de unidad.
 *
 * Una unidad puede traer la carga de VARIOS proveedores: cada proveedor de la
 * carga ocupa una fila de esta tabla puente. El orden de inserción (columna
 * `id` autoincremental) define cuál es el "proveedor principal" de la unidad.
 */
class ProveedorRecepcion extends Model
{
    protected $table = 'proveedor_recepcion';

    /**
     * La tabla no tiene columnas created_at / updated_at.
     */
    public $timestamps = false;

    protected $fillable = [
        'id_recepcion_unidad',
        'id_proveedor_minero',
    ];

    protected $casts = [
        'id_recepcion_unidad' => 'integer',
        'id_proveedor_minero' => 'integer',
    ];

    public function recepcionUnidad(): BelongsTo
    {
        return $this->belongsTo(RecepcionUnidad::class, 'id_recepcion_unidad');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'id_proveedor_minero');
    }
}
