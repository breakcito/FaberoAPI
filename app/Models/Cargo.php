<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cargo extends Model
{
    protected $table = 'cargo';

    public $timestamps = false;

    protected $fillable = [
        'id_area',
        'nombre',
        'estado',
    ];
}
