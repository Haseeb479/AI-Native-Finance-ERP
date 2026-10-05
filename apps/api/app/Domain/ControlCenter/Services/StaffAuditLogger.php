<?php

namespace App\Domain\ControlCenter\Services;

use App\Domain\ControlCenter\Models\StaffAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class StaffAuditLogger
{
    public function record(Request $request, string $outcome): void
    {
        $organizationId = $request->route('organizationId');
        $resourceId = $request->route('demoRequest')
            ?? $request->route('supportCase')
            ?? $request->route('staffMember')
            ?? $request->route('staffInvitation')
            ?? $request->route('organizationId');
        $metadata = ['method' => $request->method()];
        if ($resourceId instanceof Model) {
            $resourceOrganizationId = $resourceId->getAttribute('organization_id');
            if (is_string($resourceOrganizationId) && Str::isUuid($resourceOrganizationId)) {
                $organizationId ??= $resourceOrganizationId;
            }
            $resourceRole = $resourceId->getAttribute('role');
            if (is_string($resourceRole)
                && in_array($resourceRole, ['ops_admin', 'ops_manager', 'ops_sales', 'ops_support', 'ops_readonly'], true)) {
                $metadata['role'] = $resourceRole;
            }
            $resourceId = $resourceId->getKey();
        }
        if (is_scalar($resourceId)) {
            $metadata['resource_id'] = (string) $resourceId;
        }
        $auditedValues = [
            'status' => ['new', 'contacted', 'scheduled', 'closed', 'open', 'in_progress', 'waiting_on_customer', 'resolved', 'active', 'pending_mfa', 'revoked'],
            'priority' => ['low', 'normal', 'high', 'urgent'],
            'category' => ['access', 'billing', 'onboarding', 'technical', 'other'],
            'role' => ['ops_admin', 'ops_manager', 'ops_sales', 'ops_support', 'ops_readonly'],
        ];
        foreach ($auditedValues as $field => $allowedValues) {
            $value = $request->input($field);
            if (is_string($value) && in_array($value, $allowedValues, true)) {
                $metadata[$field] = $value;
            }
        }
        $requestedOrganizationId = $request->input('organization_id');
        if (is_string($requestedOrganizationId) && Str::isUuid($requestedOrganizationId)) {
            $organizationId ??= $requestedOrganizationId;
        }

        StaffAuditEvent::query()->create([
            'actor_user_id' => $request->user()?->getAuthIdentifier(),
            'action' => (string) ($request->route()?->getName() ?? 'control_center.unknown'),
            'organization_id' => Str::isUuid($organizationId) ? $organizationId : null,
            'outcome' => $outcome,
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
            'created_at' => now(),
        ]);
    }
}
