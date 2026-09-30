<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request and enforce enterprise HTTP security headers.
     * P1-30: HSTS, CSP, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, X-Frame-Options.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // Prevent MIME type sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Prevent clickjacking / framing
        $response->headers->set('X-Frame-Options', 'DENY');

        // Referrer policy
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Cross-Origin Resource Policy
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        // Permissions Policy (disable unneeded browser APIs)
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');

        // Content Security Policy
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; frame-ancestors 'none'; object-src 'none'; base-uri 'self';"
        );

        // HTTP Strict Transport Security (HSTS) - enforce for HTTPS requests or production environment
        if ($request->isSecure() || app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        return $response;
    }
}
