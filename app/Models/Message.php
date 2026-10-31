<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $table = 'messages';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = [
        'body' => 'encrypted',
        'created_at' => 'datetime',
        'read_at' => 'datetime',
    ];
}
