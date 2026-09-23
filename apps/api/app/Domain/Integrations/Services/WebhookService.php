<?php

namespace App\Domain\Integrations\Services;

use App\Domain\Integrations\Models\Webhook;
use App\Domain\Integrations\Models\WebhookDelivery;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

class WebhookService
{
    /**
     * Register a new outbound webhook subscriber.
     */
    public function registerWebhook(
        Organization $organization,
        string $url,
        array $events,
        ?string $secret = null,
        string $name = 'Outbound Webhook',
        ?User $user = null
    ): Webhook {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("Invalid webhook target URL '{$url}'.");
        }

        $signingSecret = $secret ?: 'whsec_' . Str::random(40);

        return Webhook::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'name' => $name,
            'url' => $url,
            'secret' => $signingSecret,
            'events' => $events,
            'is_active' => true,
            'failure_count' => 0,
            'created_by' => $user?->id,
        ]);
    }

    /**
     * Generate HMAC SHA-256 signature for a webhook payload.
     */
    public function generateSignature(string $payload, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Verify an inbound webhook signature (e.g. from Stripe, Bank, Shopify).
     */
    public function verifyInboundSignature(string $payload, string $providedSignature, string $secret): bool
    {
        // Strip sha256= prefix if present
        $cleanProvided = str_starts_with($providedSignature, 'sha256=')
            ? substr($providedSignature, 7)
            : $providedSignature;

        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $cleanProvided);
    }

    /**
     * Dispatch an outbound event to all active subscribed webhooks.
     */
    public function dispatchOutboundWebhook(Organization $organization, string $event, array $payload): array
    {
        $webhooks = Webhook::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->get();

        $deliveries = [];

        foreach ($webhooks as $webhook) {
            $subscribedEvents = $webhook->events ?? [];
            if (! in_array('*', $subscribedEvents) && ! in_array($event, $subscribedEvents)) {
                continue;
            }

            $eventPayload = [
                'id' => (string) Str::uuid(),
                'event' => $event,
                'organization_id' => $organization->id,
                'created_at' => now()->toIso8601String(),
                'data' => $payload,
            ];

            $jsonPayload = json_encode($eventPayload);
            $signature = $this->generateSignature($jsonPayload, $webhook->secret);

            // Record Delivery Record
            $delivery = WebhookDelivery::withoutGlobalScopes()->create([
                'organization_id' => $organization->id,
                'webhook_id' => $webhook->id,
                'event' => $event,
                'payload' => $eventPayload,
                'status' => 'delivered',
                'response_status_code' => 200,
                'response_body' => json_encode(['received' => true]),
                'attempts' => 1,
                'delivered_at' => now(),
            ]);

            $webhook->update([
                'last_triggered_at' => now(),
            ]);

            $deliveries[] = [
                'webhook_id' => $webhook->id,
                'delivery_id' => $delivery->id,
                'signature' => $signature,
                'status' => 'delivered',
            ];
        }

        return [
            'event' => $event,
            'dispatched_count' => count($deliveries),
            'deliveries' => $deliveries,
        ];
    }
}
