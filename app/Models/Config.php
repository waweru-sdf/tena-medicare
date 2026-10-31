<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Config extends Model
{
    protected $table = 'configs';
    public $timestamps = false;
    protected $guarded = [];
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $casts = [
    ];
}
