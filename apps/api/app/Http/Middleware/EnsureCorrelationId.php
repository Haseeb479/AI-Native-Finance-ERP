<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureCorrelationId
{
    /**
     * Handle an incoming request, attach correlation ID to Laravel Context and response headers (P1-35).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->header('X-Correlation-ID')
            ?: $request->header('X-Request-ID')
            ?: (string) Str::uuid();

        // Bind to Laravel 11 Context for structured logging and distributed tracing
        Context::add('correlation_id', $correlationId);
        Context::add('request_method', $request->method());
        Context::add('request_path', $request->path());

        if ($request->hasHeader('X-Organization-Id')) {
            Context::add('organization_id', $request->header('X-Organization-Id'));
        }

        if ($request->user()) {
            Context::add('user_id', $request->user()->id);
        }

        $response = $next($request);

        $response->headers->set('X-Correlation-ID', $correlationId);

        return $response;
    }
}
