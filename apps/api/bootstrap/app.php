<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(\App\Http\Middleware\EnsureCorrelationId::class);
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->api(append: [
            \App\Http\Middleware\EnsureIdempotency::class,
        ]);

        $middleware->alias([
            'api.limiter' => \App\Http\Middleware\ApiKeyRateLimiter::class,
            'internal.service' => \App\Http\Middleware\VerifyInternalServiceToken::class,
            'idempotent' => \App\Http\Middleware\EnsureIdempotency::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $correlationId = $request->header('X-Correlation-ID') ?: (string) \Illuminate\Support\Str::uuid();
                
                $statusCode = 500;
                $errorCode = 'INTERNAL_SERVER_ERROR';
                $safeMessage = 'An unexpected internal error occurred. Please reference the correlation ID for assistance.';
                $details = [];

                if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                    $statusCode = $e->getStatusCode();
                    $errorCode = match ($statusCode) {
                        404 => 'NOT_FOUND',
                        403 => 'FORBIDDEN',
                        401 => 'UNAUTHORIZED',
                        429 => 'RATE_LIMIT_EXCEEDED',
                        default => 'HTTP_ERROR',
                    };
                    $safeMessage = $e->getMessage() ?: $safeMessage;
                } elseif ($e instanceof \Illuminate\Validation\ValidationException) {
                    $statusCode = 422;
                    $errorCode = 'VALIDATION_ERROR';
                    $safeMessage = 'The given data was invalid.';
                    $details = $e->errors();
                } elseif ($e instanceof \Illuminate\Auth\AuthenticationException) {
                    $statusCode = 401;
                    $errorCode = 'UNAUTHENTICATED';
                    $safeMessage = 'Unauthenticated access.';
                } elseif ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                    $statusCode = 403;
                    $errorCode = 'FORBIDDEN';
                    $safeMessage = $e->getMessage() ?: 'This action is unauthorized.';
                }

                // In production, never leak raw SQL or system exceptions (P2-04)
                if ($statusCode >= 500 && ! config('app.debug')) {
                    \Illuminate\Support\Facades\Log::error("Unhandled exception [{$correlationId}]: " . $e->getMessage(), [
                        'exception' => get_class($e),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }

                return response()->json([
                    'data' => null,
                    'meta' => [
                        'correlation_id' => $correlationId,
                        'timestamp' => now()->toIso8601String(),
                    ],
                    'errors' => [
                        [
                            'code' => $errorCode,
                            'message' => $safeMessage,
                            'details' => $details,
                        ],
                    ],
                ], $statusCode, ['X-Correlation-ID' => $correlationId]);
            }
        });
    })->create();

