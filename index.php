<?php

/*
 * Front door of Dr. Feysel Tena Medicine. Every address that is not a real file arrives here (see .htaccess).
 * The Laravel application itself lives OUTSIDE the public folder, in the folder "tena-app", so its code,
 * settings (.env), database password and private photos can never be downloaded from the web.
 */

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$appDir = null;
foreach ([getenv('TENA_APP_DIR') ?: '', dirname(__DIR__, 2) . '/tena-app', dirname(__DIR__) . '/tena-app'] as $try) {
    if ($try !== '' && is_file($try . '/vendor/autoload.php')) {
        $appDir = $try;
        break;
    }
}
if ($appDir === null) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    exit("The service is being set up. Please try again in a few minutes.\n");
}

if (file_exists($maintenance = $appDir . '/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $appDir . '/vendor/autoload.php';

(require_once $appDir . '/bootstrap/app.php')
    ->usePublicPath(__DIR__)
    ->handleRequest(Request::capture());
