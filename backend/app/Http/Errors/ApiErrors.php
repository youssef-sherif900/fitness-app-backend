<?php

namespace App\Http\Errors;

use App\Exceptions\GuardrailViolation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/** One error shape for the whole API: {"error": {"code", "message", "details"}} (section 6). */
class ApiErrors
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(fn (Request $r) => $r->is('api/*') || $r->expectsJson());

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            $mapped = match (true) {
                $e instanceof ValidationException => [422, 'validation_failed', 'The given data was invalid.', $e->errors()],
                $e instanceof GuardrailViolation => [422, $e->errorCode, $e->getMessage(), $e->context],
                $e instanceof AuthenticationException => [401, 'unauthenticated', 'Unauthenticated.', null],
                $e instanceof AuthorizationException,
                $e instanceof AccessDeniedHttpException => [403, 'forbidden', 'This action is not allowed.', null],
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => [404, 'not_found', 'Resource not found.', null],
                $e instanceof ThrottleRequestsException => [429, 'rate_limited', 'Too many requests.', null],
                $e instanceof HttpExceptionInterface => [$e->getStatusCode(), 'http_error', $e->getMessage() ?: 'HTTP error.', null],
                default => null,
            };

            if ($mapped === null) {
                return null; // let Laravel render unexpected errors as 500
            }

            [$status, $code, $message, $details] = $mapped;

            return response()->json(['error' => array_filter([
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ], fn ($v) => $v !== null)], $status);
        });
    }
}
