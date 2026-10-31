<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Media extends Model
{
    protected $table = 'media';
    public $timestamps = false;
    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';
    protected $casts = [
        'encrypted' => 'boolean',
        'created_at' => 'datetime',
    ];
}
