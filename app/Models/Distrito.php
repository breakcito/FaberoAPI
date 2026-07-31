<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Distrito extends Model
{
    protected $table = 'distrito';

    public $timestamps = false;

    protected $fillable = [
        'id_provincia',
        //
        'nombre',
        'codigo',
    ];
}
