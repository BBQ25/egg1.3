<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureDocEaseAccess;
use App\Http\Middleware\EnsureDocEaseAdmin;
use App\Http\Middleware\EnsureDocEaseEnabled;
use App\Http\Middleware\EnsureMachineBlueprintAccess;
use App\Http\Middleware\EnsureWithinGeofence;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

foreach (['TMP', 'TEMP'] as $tempVariable) {
    putenv($tempVariable.'=C:\\laragon\\tmp');
    $_ENV[$tempVariable] = 'C:\\laragon\\tmp';
    $_SERVER[$tempVariable] = 'C:\\laragon\\tmp';
}

$csrfExceptions = ['api/devices/ingest'];
$appBasePath = trim((string) env(
    'APP_WEB_PATH',
    (string) parse_url((string) env('APP_URL', ''), PHP_URL_PATH)
), '/');
if ($appBasePath !== '') {
    $csrfExceptions[] = $appBasePath . '/api/devices/ingest';
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) use ($csrfExceptions): void {
        $middleware->validateCsrfTokens(except: $csrfExceptions);

        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'geofence' => EnsureWithinGeofence::class,
            'machine-blueprint.access' => EnsureMachineBlueprintAccess::class,
            'doc-ease.enabled' => EnsureDocEaseEnabled::class,
            'doc-ease.access' => EnsureDocEaseAccess::class,
            'doc-ease.admin' => EnsureDocEaseAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
