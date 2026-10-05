<?php

namespace App\Http\Middleware;

use App\Domain\Organization\Context\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyInternalServiceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = $request->header('Authorization');
        if (! $authHeader || ! str_starts_with($authHeader, 'Bearer ')) {
            return response()->json([
                'data' => null,
                'meta' => [],
                'errors' => [['code' => 'UNAUTHORIZED', 'message' => 'Missing internal service authorization token.']],
            ], 401);
        }

        $token = substr($authHeader, 7);
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return response()->json([
                'data' => null,
                'meta' => [],
                'errors' => [['code' => 'INVALID_TOKEN', 'message' => 'Malformed JWT token structure.']],
            ], 401);
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $secret = config('services.ai.internal_secret');
        if (! is_string($secret) || $secret === '') {
            return response()->json([
                'data' => null,
                'meta' => [],
                'errors' => [['code' => 'SERVICE_AUTH_UNAVAILABLE', 'message' => 'Internal service authentication is not configured.']],
            ], 503);
        }

        $header = $this->decodeSegment($encodedHeader);
        $payload = $this->decodeSegment($encodedPayload);
        if (! is_array($header) || ($header['alg'] ?? null) !== 'HS256' || ($header['typ'] ?? null) !== 'JWT' || ! is_array($payload)) {
            return response()->json([
                'data' => null,
                'meta' => [],
                'errors' => [['code' => 'INVALID_TOKEN', 'message' => 'Invalid internal service token header or payload.']],
            ], 401);
        }

        $expectedSignature = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$encodedHeader}.{$encodedPayload}", $secret, true)), '+/', '-_'), '=');

        if (! hash_equals($expectedSignature, $encodedSignature)) {
            return response()->json([
                'data' => null,
                'meta' => [],
                'errors' => [['code' => 'INVALID_SIGNATURE', 'message' => 'Internal service token signature verification failed.']],
            ], 401);
        }

        $requiredClaims = ['iss', 'aud', 'organization_id', 'user_id', 'user_permissions', 'jti', 'iat', 'exp'];
        foreach ($requiredClaims as $claim) {
            if (! array_key_exists($claim, $payload)) {
                return response()->json([
                    'data' => null,
                    'meta' => [],
                    'errors' => [['code' => 'INVALID_PAYLOAD', 'message' => "Required token claim '{$claim}' is missing."]],
                ], 401);
            }
        }

        if (
            $payload['iss'] !== 'laravel-finance-erp'
            || $payload['aud'] !== 'ai-tool-gateway'
            || ! is_string($payload['organization_id'])
            || $payload['organization_id'] === ''
            || ! is_numeric($payload['user_id'])
            || ! is_array($payload['user_permissions'])
            || ! is_string($payload['jti'])
            || $payload['jti'] === ''
            || ! is_numeric($payload['iat'])
            || ! is_numeric($payload['exp'])
        ) {
            return response()->json([
                'data' => null,
                'meta' => [],
                'errors' => [['code' => 'INVALID_PAYLOAD', 'message' => 'Internal service token claims are invalid.']],
            ], 401);
        }

        if ((int) $payload['exp'] <= time() || (int) $payload['iat'] > time() + 30 || (int) $payload['exp'] <= (int) $payload['iat']) {
            return response()->json([
                'data' => null,
                'meta' => [],
                'errors' => [['code' => 'TOKEN_EXPIRED', 'message' => 'Internal service token is expired or has invalid timestamps.']],
            ], 401);
        }

        // A service token is scoped to exactly one organization and one existing member.
        $routeOrgId = $request->route('orgId') ?? $request->route('organization');
        if ($routeOrgId && (string) $routeOrgId !== $payload['organization_id']) {
            return response()->json([
                'data' => null,
                'meta' => [],
                'errors' => [['code' => 'SCOPE_MISMATCH', 'message' => 'Token organization scope does not match route.']],
            ], 403);
        }

        $user = User::find($payload['user_id']);
        if (! $user || ! $user->organizations()->whereKey($payload['organization_id'])->exists()) {
            return response()->json([
                'data' => null,
                'meta' => [],
                'errors' => [['code' => 'FORBIDDEN', 'message' => 'Token user is not a member of the scoped organization.']],
            ], 403);
        }

        $request->setUserResolver(fn () => $user);
        $context = app(TenantContext::class);
        $context->setOrganization($payload['organization_id']);
        $context->setUser($user);

        if (isset($payload['entity_id'])) {
            if (! $user->organizations()->whereKey($payload['organization_id'])->first()?->entities()->whereKey($payload['entity_id'])->exists()) {
                return response()->json([
                    'data' => null,
                    'meta' => [],
                    'errors' => [['code' => 'FORBIDDEN', 'message' => 'Token entity is not part of the scoped organization.']],
                ], 403);
            }

            $context->setEntityId($payload['entity_id']);
        }

        return $next($request);
    }

    private function decodeSegment(string $segment): ?array
    {
        $decoded = base64_decode(strtr($segment, '-_', '+/') . str_repeat('=', (4 - strlen($segment) % 4) % 4), true);
        if ($decoded === false) {
            return null;
        }

        $value = json_decode($decoded, true);

        return is_array($value) ? $value : null;
    }
}
