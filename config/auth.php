<?php

// Tena signs people in with its own bearer tokens (see App\Http\Middleware\TenaAuth).
// These values only satisfy the framework.
return [
    'defaults' => ['guard' => 'web', 'passwords' => 'users'],
    'guards' => ['web' => ['driver' => 'session', 'provider' => 'users']],
    'providers' => ['users' => ['driver' => 'eloquent', 'model' => App\Models\User::class]],
    'passwords' => [],
    'password_timeout' => 10800,
];
