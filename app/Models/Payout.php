<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payout extends Model
{
    protected $table = 'payouts';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = [
    ];

    public function clinician() { return $this->belongsTo(Clinician::class); }
}
