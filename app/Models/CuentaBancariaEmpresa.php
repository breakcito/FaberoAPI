<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CuentaBancariaEmpresa extends Model
{
    protected $table = 'cuenta_bancaria_empresa';

    public $timestamps = false;

    protected $fillable = [
        'id_empresa',
        'id_banco',
        'moneda',
        'numero_cuenta',
        'cci',
        'es_para_detraccion',
        'estado',
    ];

    public function banco(): BelongsTo
    {
        return $this->belongsTo(Banco::class, 'id_banco');
    }
}
