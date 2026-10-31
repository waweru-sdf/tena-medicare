<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Clinician extends Model
{
    protected $table = 'clinicians';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = [
        'submitted_at' => 'datetime',
    ];

    public function user() { return $this->belongsTo(User::class); }
}
