<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PatientProfile extends Model
{
    protected $table = 'patient_profiles';
    public $timestamps = false;
    protected $guarded = [];
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $casts = [
        'data' => 'encrypted',
        'updated_at' => 'datetime',
    ];
}
