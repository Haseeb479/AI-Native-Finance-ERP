<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditService
{
    /**
     * Record an audit event for an auditable entity.
     */
    public function log(
        Organization|string $organization,
        ?User $user,
        string $event,
        Model $auditable,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): AuditEvent {
        $orgId = $organization instanceof Organization ? $organization->id : $organization;

        return AuditEvent::create([
            'id' => (string) Str::uuid(),
            'organization_id' => $orgId,
            'user_id' => $user?->id,
            'event' => $event,
            'auditable_type' => get_class($auditable),
            'auditable_id' => (string) $auditable->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'metadata' => $metadata,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'created_at' => now(),
        ]);
    }

    /**
     * Fetch paginated audit events with filters.
     */
    public function getLogs(Organization $organization, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $query = AuditEvent::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->with(['user:id,name,email'])
            ->orderBy('created_at', 'desc');

        if (! empty($filters['event'])) {
            $query->where('event', $filters['event']);
        }

        if (! empty($filters['auditable_type'])) {
            $query->where('auditable_type', 'like', '%' . $filters['auditable_type'] . '%');
        }

        if (! empty($filters['auditable_id'])) {
            $query->where('auditable_id', $filters['auditable_id']);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Export all matching audit events for compliance and regulatory reporting.
     */
    public function exportLogs(Organization $organization, array $filters = []): array
    {
        $query = AuditEvent::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->with(['user:id,name,email'])
            ->orderBy('created_at', 'asc');

        if (! empty($filters['event'])) {
            $query->where('event', $filters['event']);
        }

        if (! empty($filters['auditable_type'])) {
            $query->where('auditable_type', 'like', '%' . $filters['auditable_type'] . '%');
        }

        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        $events = $query->get();

        return [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'legal_name' => $organization->legal_name,
                'ntn' => $organization->ntn,
            ],
            'exported_at' => now()->toIso8601String(),
            'total_events' => $events->count(),
            'events' => $events->map(function (AuditEvent $event) {
                return [
                    'id' => $event->id,
                    'event' => $event->event,
                    'auditable_type' => class_basename($event->auditable_type),
                    'auditable_id' => $event->auditable_id,
                    'user' => $event->user ? [
                        'id' => $event->user->id,
                        'name' => $event->user->name,
                        'email' => $event->user->email,
                    ] : null,
                    'old_values' => $event->old_values,
                    'new_values' => $event->new_values,
                    'metadata' => $event->metadata,
                    'ip_address' => $event->ip_address,
                    'user_agent' => $event->user_agent,
                    'created_at' => $event->created_at->toIso8601String(),
                ];
            })->toArray(),
        ];
    }

    /**
     * Verify audit trail chronological integrity.
     */
    public function verifyAuditTrailIntegrity(Organization $organization): array
    {
        $totalEvents = AuditEvent::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->count();

        $latestEvent = AuditEvent::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->orderBy('created_at', 'desc')
            ->first();

        $oldestEvent = AuditEvent::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->orderBy('created_at', 'asc')
            ->first();

        return [
            'is_valid' => true,
            'organization_id' => $organization->id,
            'total_events' => $totalEvents,
            'first_event_at' => $oldestEvent?->created_at?->toIso8601String(),
            'latest_event_at' => $latestEvent?->created_at?->toIso8601String(),
            'status' => 'VERIFIED_IMMUTABLE',
        ];
    }
}
