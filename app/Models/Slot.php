<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Slot extends Model
{
    protected $table = 'slots';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = [
        'starts_at' => 'datetime',
        'hold_expires_at' => 'datetime',
        'booked' => 'boolean',
    ];

    public function clinician() { return $this->belongsTo(Clinician::class); }
}
