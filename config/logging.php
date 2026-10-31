<?php

return [
    'default' => env('LOG_CHANNEL', 'daily'),
    'deprecations' => ['channel' => 'null', 'trace' => false],
    'channels' => [
        'daily' => ['driver' => 'daily', 'path' => storage_path('logs/laravel.log'), 'level' => env('LOG_LEVEL', 'warning'), 'days' => 14],
        'single' => ['driver' => 'single', 'path' => storage_path('logs/laravel.log'), 'level' => env('LOG_LEVEL', 'warning')],
        'null' => ['driver' => 'monolog', 'handler' => Monolog\Handler\NullHandler::class],
    ],
];
