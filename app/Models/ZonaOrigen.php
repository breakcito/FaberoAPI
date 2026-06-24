<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZonaOrigen extends Model
{
    protected $table = 'zona_origen';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'created_at'
    ];
}
