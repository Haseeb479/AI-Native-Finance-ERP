<?php

namespace App\Http\Middleware;

use App\Domain\Security\Services\SecurityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyRateLimiter
{
    public function __construct(
        protected SecurityService $securityService
    ) {}

    /**
     * Handle an incoming request and enforce rate limits.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKeyHeader = $request->header('X-API-Key');
        $authHeader = $request->header('Authorization');

        $plainKey = null;
        if ($apiKeyHeader) {
            $plainKey = $apiKeyHeader;
        } elseif ($authHeader && str_starts_with($authHeader, 'Bearer erp_live_')) {
            $plainKey = substr($authHeader, 7);
        }

        $rateLimit = 60;
        $identifier = $request->ip();

        if ($plainKey) {
            $verifiedKey = $this->securityService->verifyApiKey($plainKey, $request->ip());
            if (! $verifiedKey) {
                return response()->json([
                    'data' => null,
                    'meta' => [],
                    'errors' => [
                        ['code' => 'INVALID_API_KEY', 'message' => 'The provided API Key is invalid, expired, or IP blocked.'],
                    ],
                ], 401);
            }

            $rateLimit = $verifiedKey->rate_limit_per_minute ?? 60;
            $identifier = "api_key:" . $verifiedKey->id;
        } elseif ($request->user()) {
            $identifier = "user:" . $request->user()->id;
            $rateLimit = 120;
        }

        $rateResult = $this->securityService->checkRateLimit($identifier, $rateLimit, 60);

        if (! $rateResult['allowed']) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'limit' => $rateResult['limit'],
                    'retry_after' => $rateResult['retry_after'],
                ],
                'errors' => [
                    ['code' => 'RATE_LIMIT_EXCEEDED', 'message' => 'Too many requests. Please retry after ' . $rateResult['retry_after'] . ' seconds.'],
                ],
            ], 429, [
                'X-RateLimit-Limit' => $rateResult['limit'],
                'X-RateLimit-Remaining' => 0,
                'Retry-After' => $rateResult['retry_after'],
            ]);
        }

        $response = $next($request);

        $response->headers->set('X-RateLimit-Limit', (string) $rateResult['limit']);
        $response->headers->set('X-RateLimit-Remaining', (string) $rateResult['remaining']);

        return $response;
    }
}
