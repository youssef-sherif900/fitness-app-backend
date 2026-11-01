<?php

use App\Http\Errors\ApiErrors;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(__DIR__ . '/../routes/channels.php', [
        'prefix' => 'api',
        'middleware' => ['api', 'auth:sanctum'],
    ])
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*');
        $middleware->alias(['ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class]);
    })
    ->withExceptions(function (Exceptions $e) {
        // Section 6: every API error shares one {"error": {code, message, details}} shape.
        ApiErrors::register($e);
    })
    ->create();
