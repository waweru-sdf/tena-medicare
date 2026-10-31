<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Note extends Model
{
    protected $table = 'notes';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = [
        'assessment' => 'encrypted',
        'plan' => 'encrypted',
        'summary_for_patient' => 'encrypted',
        'finalized' => 'boolean',
        'finalized_at' => 'datetime',
    ];
}
