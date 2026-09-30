<?php

namespace App\Http\Middleware;

use App\Domain\Shared\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIdempotency
{
    /**
     * Handle an incoming request.
     * P1-22: Enterprise Idempotency Middleware for financial mutations.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only enforce idempotency for state-mutating methods
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $idempotencyKey = $request->header('Idempotency-Key') ?? $request->header('X-Idempotency-Key');

        if (empty($idempotencyKey)) {
            return $next($request);
        }

        $idempotencyKey = trim((string) $idempotencyKey);

        // Resolve organization ID from route parameters or request context
        $orgId = $this->resolveOrganizationId($request);

        if (! $orgId) {
            return $next($request);
        }

        // Canonical payload hash (ignoring CSRF and volatile authorization tokens)
        $payload = $request->except(['_token', 'password', 'password_confirmation', 'signature']);
        ksort($payload);
        $requestHash = hash('sha256', $request->method() . '|' . $request->path() . '|' . json_encode($payload));

        // Check for existing record
        $existing = IdempotencyKey::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            if ($existing->isCompleted()) {
                // Reject payload tampering with same idempotency key
                if ($existing->request_hash !== $requestHash) {
                    return response()->json([
                        'data' => null,
                        'meta' => ['timestamp' => now()->toISOString()],
                        'errors' => [
                            [
                                'code' => 'IDEMPOTENCY_KEY_MISMATCH',
                                'message' => 'Idempotency key has already been used with differing request parameters.',
                            ],
                        ],
                    ], 422);
                }

                // Replay the cached response
                return response()->json(
                    $existing->response_body ?? [],
                    $existing->response_code ?: 200,
                    ['X-Idempotent-Replay' => 'true']
                );
            }

            if ($existing->isInProgress()) {
                // If locked within the last 60 seconds, reject concurrent execution
                if ($existing->locked_at && $existing->locked_at->diffInSeconds(now()) < 60) {
                    return response()->json([
                        'data' => null,
                        'meta' => ['timestamp' => now()->toISOString()],
                        'errors' => [
                            [
                                'code' => 'MUTATION_IN_PROGRESS',
                                'message' => 'A request with this idempotency key is currently being processed.',
                            ],
                        ],
                    ], 409);
                }
            }
        }

        // Atomically claim idempotency key
        $userId = $request->user()?->id;

        try {
            $record = IdempotencyKey::withoutGlobalScopes()->updateOrCreate(
                [
                    'organization_id' => $orgId,
                    'idempotency_key' => $idempotencyKey,
                ],
                [
                    'user_id' => $userId,
                    'request_method' => $request->method(),
                    'request_path' => substr($request->path(), 0, 1000),
                    'request_hash' => $requestHash,
                    'status' => 'in_progress',
                    'locked_at' => now(),
                ]
            );
        } catch (\Throwable $e) {
            // Unique key collision from concurrent arrival
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'MUTATION_IN_PROGRESS',
                        'message' => 'A request with this idempotency key is currently being processed.',
                    ],
                ],
            ], 409);
        }

        /** @var Response $response */
        $response = $next($request);

        // Save response for safe replays if execution was successful or client-side failure (non-500)
        if ($response->getStatusCode() < 500) {
            $body = null;
            if ($response instanceof JsonResponse) {
                $body = $response->getData(true);
            } else {
                $decoded = json_decode($response->getContent(), true);
                $body = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
            }

            $record->update([
                'status' => 'completed',
                'response_code' => $response->getStatusCode(),
                'response_body' => $body,
            ]);
        } else {
            // Failed on 500 error: allow caller to retry with same idempotency key
            $record->update([
                'status' => 'failed',
            ]);
        }

        return $response;
    }

    /**
     * Resolve the target organization ID for tenant scoping.
     */
    protected function resolveOrganizationId(Request $request): ?string
    {
        $orgId = $request->route('orgId')
            ?? $request->route('organizationId')
            ?? $request->route('organization')?->id
            ?? $request->header('X-Organization-Id')
            ?? $request->user()?->default_organization_id;

        if ($orgId instanceof \App\Domain\Organization\Models\Organization) {
            return $orgId->id;
        }

        if (is_string($orgId) && ! empty($orgId)) {
            return $orgId;
        }

        return $request->user()?->organizations()->value('organizations.id');
    }
}
