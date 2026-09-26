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

        $secret = config('services.ai.internal_secret', 'ai-native-finance-erp-internal-service-secret-key');
        $expectedSignature = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$encodedHeader}.{$encodedPayload}", $secret, true)), '+/', '-_'), '=');

        if (! hash_equals($expectedSignature, $encodedSignature)) {
            return response()->json([
                'data' => null,
                'meta' => [],
                'errors' => [['code' => 'INVALID_SIGNATURE', 'message' => 'Internal service token signature verification failed.']],
            ], 401);
        }

        $payload = json_decode(base64_decode(strtr($encodedPayload, '-_', '+/')), true);
        if (! is_array($payload)) {
            return response()->json([
                'data' => null,
                'meta' => [],
                'errors' => [['code' => 'INVALID_PAYLOAD', 'message' => 'Invalid token payload json.']],
            ], 401);
        }

        // Validate expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return response()->json([
                'data' => null,
                'meta' => [],
                'errors' => [['code' => 'TOKEN_EXPIRED', 'message' => 'Internal service token has expired.']],
            ], 401);
        }

        // Validate scope
        $routeOrgId = $request->route('orgId') ?? $request->route('organization');
        if ($routeOrgId && isset($payload['organization_id']) && $routeOrgId !== $payload['organization_id']) {
            return response()->json([
                'data' => null,
                'meta' => [],
                'errors' => [['code' => 'SCOPE_MISMATCH', 'message' => 'Token organization scope does not match route.']],
            ], 403);
        }

        // Populate request user and TenantContext
        if (isset($payload['user_id'])) {
            $user = User::find($payload['user_id']);
            if ($user) {
                $request->setUserResolver(fn () => $user);
                $context = app(TenantContext::class);
                $context->setOrganization($payload['organization_id'] ?? $routeOrgId);
                $context->setUser($user);
                if (isset($payload['entity_id'])) {
                    $context->setEntityId($payload['entity_id']);
                }
            }
        }

        return $next($request);
    }
}
