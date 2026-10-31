<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'users';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = [
        'consent_at' => 'datetime',
        'mfa_on' => 'boolean',
        'mfa_secret' => 'encrypted',
    ];

    public function clinician() { return $this->hasOne(Clinician::class, 'user_id'); }
}
