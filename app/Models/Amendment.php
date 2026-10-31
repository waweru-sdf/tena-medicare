<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Amendment extends Model
{
    protected $table = 'amendments';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = [
        'text' => 'encrypted',
        'created_at' => 'datetime',
    ];
}
