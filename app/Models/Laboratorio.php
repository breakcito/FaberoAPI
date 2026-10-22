<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Laboratorio extends Model
{
    protected $table = 'laboratorio';

    public $timestamps = false;

    protected $fillable = [
        'id_empleado_registro',
        'ruc',
        'nombre',
        'created_at',
    ];

    public function empleado_registro(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'id_empleado_registro');
    }
}
