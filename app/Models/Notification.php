<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $table = 'notifications';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = [
        'at' => 'datetime',
    ];
}
