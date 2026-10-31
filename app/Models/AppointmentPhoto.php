<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppointmentPhoto extends Model
{
    protected $table = 'appointment_photos';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = [
    ];
}
