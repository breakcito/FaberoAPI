<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketBalanza extends Model
{
    protected $table = 'ticket_balanza';

    public $timestamps = false;

    protected $fillable = [
        'numero',
        'created_at',
    ];
}
