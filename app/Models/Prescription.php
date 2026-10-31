<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Prescription extends Model
{
    protected $table = 'prescriptions';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = [
        'issued_at' => 'datetime',
    ];
}
