<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $h = $response->headers;
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $h->set('X-Frame-Options', 'SAMEORIGIN');
        if ($request->is('api/*') && !$h->has('Cache-Control')) {
            $h->set('Cache-Control', 'no-store, private');   // health and money data must never sit in a shared cache
        }
        return $response;
    }
}
