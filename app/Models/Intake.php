<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Intake extends Model
{
    protected $table = 'intakes';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = [
        'answers' => 'encrypted',
        'updated_at' => 'datetime',
    ];
}
