<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api',
        then: function () {
            // The website itself (the React app). Registered last because it is a catch-all.
            \Illuminate\Support\Facades\Route::group([], base_path('routes/public.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // The site sits behind Hostinger's CDN/proxy: trust it so https and the visitor address are read correctly.
        $middleware->trustProxies(at: '*');
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->alias(['tena' => \App\Http\Middleware\TenaAuth::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // The website reads the "error" key of every failed reply.
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }
            $status = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $e->getStatusCode() : 500;
            $msg = match (true) {
                $status === 404 => 'Not found.',
                $status === 405 => 'Method not allowed.',
                $status === 413 => 'That file is too large.',
                $status === 429 => 'Too many attempts. Wait a minute and try again.',
                $status >= 500 => 'Something went wrong. Please try again.',
                default => 'Request failed.',
            };
            if ($status >= 500) {
                report($e);
            }
            return response()->json(['error' => $msg], $status);
        });
    })->create();
