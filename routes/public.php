<?php

use Illuminate\Support\Facades\Route;

// The Tena website is a single-page React app. Any address that is not a real file or an API call gets index.html.
Route::get('/{any?}', function (string $any = '') {
    if (str_starts_with($any, 'api/')) {
        return response()->json(['error' => 'Not found.'], 404);
    }
    if (preg_match('~\.[A-Za-z0-9]{1,5}$~', $any)) {      // a missing picture, script or style: say so plainly
        return response('Not found', 404, ['Content-Type' => 'text/plain']);
    }
    $index = public_path('index.html');
    if (!is_file($index)) {
        return response('The website files are not uploaded yet.', 503, ['Content-Type' => 'text/plain']);
    }
    return response(file_get_contents($index), 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-cache']);
})->where('any', '.*');
