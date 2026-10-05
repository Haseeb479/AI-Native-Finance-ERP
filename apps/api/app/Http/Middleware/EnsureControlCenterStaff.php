<?php

namespace App\Http\Middleware;

use App\Domain\ControlCenter\Services\StaffAuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureControlCenterStaff
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $email = strtolower(trim((string) $request->user()?->email));
        $configuredEmails = config('control_center.staff_emails', []);
        $isBootstrapStaff = $email !== ''
            && is_array($configuredEmails)
            && in_array($email, $configuredEmails, true);
        $rolesByEmail = config('control_center.staff_roles', []);
        $staffRecord = ! $isBootstrapStaff && $request->user()
            ? DB::table('control_center_staff')
                ->where('user_id', $request->user()->getAuthIdentifier())
                ->whereIn('status', ['active', 'pending_mfa'])
                ->first(['role', 'status'])
            : null;
        $role = $isBootstrapStaff && is_array($rolesByEmail)
            ? ($rolesByEmail[$email] ?? 'ops_readonly')
            : ($staffRecord->role ?? 'ops_readonly');
        $mfaSetupPreflight = in_array('mfa_setup', $roles, true);
        $requiredRoles = array_values(array_diff($roles, ['mfa_setup']));
        $mfaEnabled = $request->user()?->hasEnabledTwoFactor() ?? false;
        $emailVerified = $request->user()?->email_verified_at !== null;
        $isProvisioned = $emailVerified && (
            $isBootstrapStaff
            || ($staffRecord !== null && (
                $staffRecord->status === 'active'
                || ($mfaSetupPreflight && $staffRecord->status === 'pending_mfa')
            ))
        );
        $isAuthorized = $isProvisioned
            && ($mfaSetupPreflight || $mfaEnabled)
            && ($requiredRoles === [] || in_array($role, $requiredRoles, true));

        app(StaffAuditLogger::class)->record($request, $isAuthorized ? 'allowed' : 'denied');

        if (! $isAuthorized) {
            abort(403, $isProvisioned
                ? (! $mfaEnabled && ! $mfaSetupPreflight
                    ? 'Multi-factor authentication must be enabled before using Finova Operations.'
                    : 'Your Finova staff role does not allow this action.')
                : 'Finova Operations staff access is not provisioned.');
        }

        $request->attributes->set('control_center_staff_role', $role);

        return $next($request);
    }
}
