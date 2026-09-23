<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Models\IntegrationSyncLog;
use App\Domain\Integrations\Models\Webhook;
use App\Domain\Integrations\Services\IntegrationManager;
use App\Domain\Integrations\Services\WebhookService;
use App\Domain\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrationController extends Controller
{
    public function __construct(
        protected IntegrationManager $integrationManager,
        protected WebhookService $webhookService
    ) {}

    /**
     * List integrations.
     */
    public function index(Request $request, string $orgId): JsonResponse
    {
        $integrations = Integration::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->get();

        return response()->json([
            'data' => $integrations,
            'meta' => ['total' => $integrations->count()],
            'errors' => [],
        ]);
    }

    /**
     * Register new integration connector.
     */
    public function store(Request $request, string $orgId): JsonResponse
    {
        $organization = Organization::findOrFail($orgId);

        $validated = $request->validate([
            'provider' => ['required', 'string'],
            'name' => ['required', 'string', 'max:100'],
            'credentials' => ['nullable', 'array'],
            'settings' => ['nullable', 'array'],
        ]);

        $integration = $this->integrationManager->registerIntegration(
            $organization,
            $validated['provider'],
            $validated['name'],
            $validated['credentials'] ?? [],
            $validated['settings'] ?? [],
            $request->user()
        );

        return response()->json([
            'data' => $integration,
            'meta' => ['message' => 'Integration registered successfully.'],
            'errors' => [],
        ], 201);
    }

    /**
     * Test integration connection.
     */
    public function testConnection(Request $request, string $orgId, string $id): JsonResponse
    {
        $integration = Integration::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->findOrFail($id);

        $health = $this->integrationManager->testConnection($integration);

        return response()->json([
            'data' => $health,
            'meta' => ['integration_id' => $integration->id],
            'errors' => [],
        ]);
    }

    /**
     * List integration sync logs.
     */
    public function indexLogs(Request $request, string $orgId): JsonResponse
    {
        $query = IntegrationSyncLog::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->with('integration')
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $logs = $query->paginate(20);

        return response()->json([
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'total' => $logs->total(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Retry failed sync log.
     */
    public function retryLog(Request $request, string $orgId, string $id): JsonResponse
    {
        $log = IntegrationSyncLog::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->findOrFail($id);

        $updated = $this->integrationManager->retryFailedSync($log);

        return response()->json([
            'data' => $updated,
            'meta' => ['message' => 'Sync log retried successfully.'],
            'errors' => [],
        ]);
    }

    /**
     * List webhooks.
     */
    public function indexWebhooks(Request $request, string $orgId): JsonResponse
    {
        $webhooks = Webhook::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->get();

        return response()->json([
            'data' => $webhooks,
            'meta' => ['total' => $webhooks->count()],
            'errors' => [],
        ]);
    }

    /**
     * Register outbound webhook.
     */
    public function storeWebhook(Request $request, string $orgId): JsonResponse
    {
        $organization = Organization::findOrFail($orgId);

        $validated = $request->validate([
            'url' => ['required', 'url'],
            'events' => ['required', 'array', 'min:1'],
            'name' => ['nullable', 'string', 'max:100'],
            'secret' => ['nullable', 'string', 'max:128'],
        ]);

        $webhook = $this->webhookService->registerWebhook(
            $organization,
            $validated['url'],
            $validated['events'],
            $validated['secret'] ?? null,
            $validated['name'] ?? 'Outbound Webhook',
            $request->user()
        );

        return response()->json([
            'data' => array_merge($webhook->toArray(), ['secret' => $webhook->secret]),
            'meta' => ['message' => 'Webhook registered. Store your secret key securely.'],
            'errors' => [],
        ], 201);
    }

    /**
     * Dispatch test event to webhook subscribers.
     */
    public function triggerTestWebhook(Request $request, string $orgId, string $id): JsonResponse
    {
        $organization = Organization::findOrFail($orgId);
        $webhook = Webhook::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->findOrFail($id);

        $event = $request->input('event', 'invoice.posted');
        $payload = $request->input('payload', [
            'invoice_number' => 'INV-2026-TEST',
            'amount' => 150000.00,
            'status' => 'posted',
        ]);

        $result = $this->webhookService->dispatchOutboundWebhook($organization, $event, $payload);

        return response()->json([
            'data' => $result,
            'meta' => ['message' => 'Outbound webhook dispatched.'],
            'errors' => [],
        ]);
    }

    /**
     * Verify inbound webhook HMAC SHA-256 signature.
     */
    public function verifyInboundWebhook(Request $request, string $orgId): JsonResponse
    {
        $validated = $request->validate([
            'payload' => ['required', 'string'],
            'signature' => ['required', 'string'],
            'secret' => ['required', 'string'],
        ]);

        $isValid = $this->webhookService->verifyInboundSignature(
            $validated['payload'],
            $validated['signature'],
            $validated['secret']
        );

        return response()->json([
            'data' => [
                'is_valid' => $isValid,
                'algorithm' => 'HMAC-SHA256',
            ],
            'meta' => ['verified' => $isValid],
            'errors' => [],
        ]);
    }
}
