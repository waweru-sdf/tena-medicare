<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Audit extends Model
{
    protected $table = 'audits';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = [
        'at' => 'datetime',
    ];

    /** Writes one line in the activity trail. Never throws: a log problem must not break what the person is doing. */
    public static function record(string $action, string $detail = '', ?int $userId = null): void
    {
        try {
            $userId ??= optional(request()->attributes->get('user'))->id;
            self::create(['at' => now(), 'user_id' => $userId, 'action' => substr($action, 0, 80), 'detail' => substr($detail, 0, 255)]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
