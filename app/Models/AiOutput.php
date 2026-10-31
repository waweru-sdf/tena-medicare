<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiOutput extends Model
{
    protected $table = 'ai_outputs';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = [
        'draft' => 'encrypted',
        'approved_at' => 'datetime',
    ];
}
