<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Provincia extends Model
{
    protected $table = 'provincia';

    public $timestamps = false;

    protected $fillable = [
        'id_departamento',
        //
        'nombre',
        'codigo',
    ];
}
