<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;

class Throttle
{
    /** True while `$key` has made fewer than `$max` attempts in the last `$seconds`; counts this attempt. */
    public static function allow(string $key, int $max, int $seconds = 3600): bool
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            return false;
        }
        RateLimiter::hit($key, $seconds);
        return true;
    }
}
