<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    protected $table = 'appointments';
    public $timestamps = false;
    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';
    protected $casts = [
        'chat_until' => 'datetime',
        'reminded' => 'boolean',
    ];

    public function slot() { return $this->belongsTo(Slot::class); }
    public function clinician() { return $this->belongsTo(Clinician::class); }
    public function patient() { return $this->belongsTo(User::class, 'patient_id'); }
}
