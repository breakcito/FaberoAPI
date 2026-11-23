<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo que representa la recepción de unidades.
 *
 * Los proveedores de la carga viven en `proveedor_recepcion` (tabla puente)
 * y las guías por proveedor en la columna JSON `guias`:
 *
 * [
 *   {
 *     "id_proveedor": 1,
 *     "guia_remitente": "",
 *     "guia_transportista": "",
 *     "guia_remitente_archivo": null,
 *     "guia_transportista_archivo": null
 *   }
 * ]
 */
class RecepcionUnidad extends Model
{
    protected $table = 'recepcion_unidad';

    public $timestamps = false;

    protected $fillable = [
        'id_distribucion',
        'id_empleado_recepcion',
        'id_vehiculo',
        'id_vehiculo_carreta',
        'id_empresa_transporte',
        'id_tipo_vehiculo',
        'id_conductor',
        'tipo_ingreso',
        'fecha_hora_ingreso',
        'evidencias',
        'observacion',
        'log_cambios',
        'estado',
        'estado_salida',
        'fecha_hora_salida',
        'observacion_salida',
        'id_sucursal',
        'fecha_hora_inicio_pesaje',
        'fecha_hora_final_pesaje',
        'estado_pesaje',
        'id_empleado_autoriza',
        'es_programacion',
        'fecha_estimada_llegada',
        'guias',
        'es_recepcion_ficticia',
        'id_ticket_recepcion_unidades',
        'campos_bloqueados',
    ];

    protected $casts = [
        'evidencias' => 'array',
        'log_cambios' => 'array',
        'es_recepcion_ficticia' => 'boolean',
        'campos_bloqueados' => 'array',
        'guias' => 'array',
    ];

    public function guiasPrimerTramo(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(GuiaPrimerTramo::class, 'id_recepcion_unidad');
    }

    /**
     * Proveedores de la carga que trajo esta unidad (uno por fila).
     */
    public function proveedores(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProveedorRecepcion::class, 'id_recepcion_unidad');
    }
}
