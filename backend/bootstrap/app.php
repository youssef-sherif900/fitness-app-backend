<?php

use App\Http\Errors\ApiErrors;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
    ->withExceptions(function (Illuminate\Foundation\Configuration\Exceptions $e) {
        $e->shouldRenderJsonWhen(fn(Request $r) => $r->is('api/*') || $r->expectsJson());
        $err = fn($code, $msg, $status, $details = []) => response()->json([
            'error' => [
                'code' => $code,
                'message' => $msg,
                'details' => (object) $details,
                'request_id' => request()->header('X-Request-Id') ?: (string) Str::uuid(),
            ]
        ], $status);
        $e->render(fn(ApiException $x) => $err($x->errorCode, $x->getMessage(), $x->status, $x->details));
        $e->render(fn(ValidationException $x) => $err('VALIDATION_ERROR', 'The request contains invalid fields.', 422, $x->errors()));
        $e->render(fn(AuthenticationException $x) => $err('UNAUTHENTICATED', 'Authentication required.', 401));
        $e->render(fn(AuthorizationException $x) => $err('FORBIDDEN', 'You do not have permission.', 403));
        $e->render(fn(ModelNotFoundException $x) => $err('NOT_FOUND', 'Resource not found.', 404));
        $e->render(fn(NotFoundHttpException $x) => $err('NOT_FOUND', 'Resource not found.', 404));
        $e->render(fn(ThrottleRequestsException $x) => $err('RATE_LIMITED', 'Too many requests.', 429));
    })
    ->create();
