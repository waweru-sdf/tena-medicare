<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    protected $table = 'tickets';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = [
        'due_at' => 'datetime',
        'created' => 'datetime',
    ];

    public function user() { return $this->belongsTo(User::class); }
}
