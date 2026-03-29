<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Normalize temp env vars across local Laragon and Linux hosts.
$tempDirectory = rtrim((string) (sys_get_temp_dir() ?: ''), DIRECTORY_SEPARATOR);
if ($tempDirectory !== '') {
    foreach (['TMP', 'TEMP', 'TMPDIR'] as $tempVariable) {
        if (function_exists('putenv')) {
            @putenv($tempVariable.'='.$tempDirectory);
        }

        $_ENV[$tempVariable] = $tempDirectory;
        $_SERVER[$tempVariable] = $tempDirectory;
    }
}

require __DIR__.'/../bootstrap/polyfills.php';

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
