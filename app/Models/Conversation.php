<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $table = 'conversations';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = [
    ];

    public function patient() { return $this->belongsTo(User::class, 'patient_id'); }
    public function clinician() { return $this->belongsTo(Clinician::class); }
}
