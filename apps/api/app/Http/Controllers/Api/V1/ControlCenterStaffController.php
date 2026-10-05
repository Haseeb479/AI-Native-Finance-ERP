<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ControlCenter\Models\StaffMember;
use App\Domain\ControlCenter\Models\StaffAuditEvent;
use App\Domain\ControlCenter\Models\StaffInvitation;
use App\Notifications\ControlCenterStaffInvitationNotification;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ControlCenterStaffController extends Controller
{
    private const ROLES = ['ops_admin', 'ops_manager', 'ops_sales', 'ops_support', 'ops_readonly'];

    public function index(): JsonResponse
    {
        $members = StaffMember::query()
            ->join('users', 'users.id', '=', 'control_center_staff.user_id')
            ->orderBy('users.name')
            ->get([
                'control_center_staff.id',
                'users.id as user_id',
                'users.name',
                'users.email',
                'control_center_staff.role',
                'control_center_staff.status',
                'control_center_staff.created_at',
            ])
            ->map(fn ($member): array => [
                'id' => $member->id,
                'user_id' => (string) $member->user_id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->role,
                'status' => $member->status,
                'created_at' => $member->created_at,
                'is_bootstrap' => false,
            ]);

        $emails = config('control_center.staff_emails', []);
        $roles = config('control_center.staff_roles', []);
        $bootstrapMembers = User::query()
            ->when(is_array($emails) && $emails !== [], fn ($query) => $query
                ->whereIn(DB::raw('LOWER(email)'), $emails))
            ->when(! is_array($emails) || $emails === [], fn ($query) => $query->whereRaw('1 = 0'))
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user): array => [
                'id' => 'bootstrap-'.$user->id,
                'user_id' => (string) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => is_array($roles) ? ($roles[strtolower($user->email)] ?? 'ops_readonly') : 'ops_readonly',
                'status' => 'active',
                'created_at' => null,
                'is_bootstrap' => true,
            ]);

        return response()->json([
            'data' => ['staff' => $bootstrapMembers->merge($members)->values()],
            'errors' => [],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'role' => ['required', Rule::in(self::ROLES)],
        ]);
        $email = strtolower(trim($validated['email']));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if (! $user) {
            throw ValidationException::withMessages(['email' => 'Provision the staff account through the approved account process first.']);
        }
        if (! $user->email_verified_at) {
            throw ValidationException::withMessages(['email' => 'Verify the staff account email before provisioning access.']);
        }
        if (in_array($email, config('control_center.staff_emails', []), true)) {
            throw ValidationException::withMessages(['email' => 'Bootstrap staff are managed through deployment configuration.']);
        }
        if (StaffMember::query()->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'This staff account is already provisioned.']);
        }

        $member = StaffMember::query()->create([
            'user_id' => $user->id,
            'role' => $validated['role'],
            'status' => $user->hasEnabledTwoFactor() ? 'active' : 'pending_mfa',
            'provisioned_by_user_id' => $request->user()->getAuthIdentifier(),
        ]);

        return response()->json([
            'data' => ['staff' => [
                'id' => $member->id,
                'user_id' => (string) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $member->role,
                'status' => $member->status,
                'created_at' => $member->created_at,
                'is_bootstrap' => false,
            ]],
            'errors' => [],
        ], 201);
    }

    public function update(Request $request, StaffMember $staffMember): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['sometimes', Rule::in(self::ROLES)],
            'status' => ['sometimes', Rule::in(['active', 'revoked'])],
        ]);
        if ($validated === []) {
            throw ValidationException::withMessages(['staff' => 'Provide a role or status change.']);
        }

        DB::transaction(function () use ($staffMember, $validated): void {
            $member = StaffMember::query()->whereKey($staffMember->id)->lockForUpdate()->firstOrFail();
            $role = $validated['role'] ?? $member->role;
            $status = $validated['status'] ?? $member->status;
            $user = User::query()->findOrFail($member->user_id);
            if ($status === 'active' && ! $user->hasEnabledTwoFactor()) {
                throw ValidationException::withMessages(['staff' => 'The staff member must complete MFA setup before access can be activated.']);
            }
            $removingAdmin = $member->role === 'ops_admin'
                && $member->status === 'active'
                && ($role !== 'ops_admin' || $status !== 'active');

            if ($removingAdmin && ! $this->hasOtherActiveAdmin($member->id)) {
                throw ValidationException::withMessages(['staff' => 'At least one active Operations administrator must remain.']);
            }

            $member->fill($validated)->save();
        });

        $member = $staffMember->fresh();
        $user = User::query()->findOrFail($member->user_id);

        return response()->json([
            'data' => ['staff' => [
                'id' => $member->id,
                'user_id' => (string) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $member->role,
                'status' => $member->status,
                'created_at' => $member->created_at,
                'is_bootstrap' => false,
            ]],
            'errors' => [],
        ]);
    }

    public function completeMfaSetup(Request $request): JsonResponse
    {
        if (! $request->user()->hasEnabledTwoFactor()) {
            throw ValidationException::withMessages(['mfa' => 'Complete MFA enrollment before activating staff access.']);
        }

        $member = StaffMember::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->where('status', 'pending_mfa')
            ->first();
        if ($member) {
            $member->forceFill(['status' => 'active'])->save();
        }

        return response()->json([
            'data' => ['active' => true],
            'errors' => [],
        ]);
    }

    public function invitations(): JsonResponse
    {
        $invitations = StaffInvitation::query()
            ->whereNull('accepted_at')
            ->latest()
            ->get(['id', 'email', 'role', 'expires_at', 'created_at'])
            ->map(fn (StaffInvitation $invitation): array => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'expires_at' => $invitation->expires_at,
                'created_at' => $invitation->created_at,
                'status' => $invitation->expires_at->isPast() ? 'expired' : 'pending',
            ]);

        return response()->json(['data' => ['invitations' => $invitations], 'errors' => []]);
    }

    public function invite(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'role' => ['required', Rule::in(self::ROLES)],
        ]);
        $email = strtolower(trim($validated['email']));
        if (in_array($email, config('control_center.staff_emails', []), true)) {
            throw ValidationException::withMessages(['email' => 'Bootstrap staff are managed through deployment configuration.']);
        }
        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => 'This email already has a Finova account. Use the existing-account access form instead.']);
        }

        $portalUrl = config('control_center.ops_portal_url');
        $portalParts = parse_url($portalUrl);
        $portalHost = is_array($portalParts) ? ($portalParts['host'] ?? null) : null;
        $portalScheme = is_array($portalParts) ? ($portalParts['scheme'] ?? null) : null;
        $isLoopbackPortal = is_string($portalHost)
            && in_array(strtolower($portalHost), ['localhost', '127.0.0.1', '::1'], true);
        $portalHasOnlyOrigin = is_array($portalParts)
            && ! isset($portalParts['user'], $portalParts['pass'], $portalParts['query'], $portalParts['fragment'])
            && in_array($portalParts['path'] ?? '', ['', '/'], true);
        if (! is_string($portalHost)
            || ! in_array(strtolower($portalHost), config('control_center.ops_allowed_hosts', []), true)
            || ! in_array($portalScheme, ['http', 'https'], true)
            || ! $portalHasOnlyOrigin
            || (config('control_center.require_https', false) && ! $isLoopbackPortal && $portalScheme !== 'https')) {
            return response()->json([
                'error' => 'Staff invitation delivery is not configured with an allowed Operations portal URL.',
            ], 503);
        }

        $token = Str::random(64);
        $invitation = StaffInvitation::query()->where('email', $email)->first();
        if ($invitation?->accepted_at) {
            throw ValidationException::withMessages(['email' => 'This invitation has already been accepted.']);
        }
        if ($invitation) {
            $invitation->fill([
                'role' => $validated['role'],
                'token_hash' => Hash::make($token),
                'invited_by_user_id' => $request->user()->getAuthIdentifier(),
                'expires_at' => now()->addHours(48),
            ])->save();
        } else {
            $invitation = StaffInvitation::query()->create([
                'email' => $email,
                'role' => $validated['role'],
                'token_hash' => Hash::make($token),
                'invited_by_user_id' => $request->user()->getAuthIdentifier(),
                'expires_at' => now()->addHours(48),
            ]);
        }

        try {
            Notification::route('mail', $email)->notify(
                new ControlCenterStaffInvitationNotification($token, $portalUrl, $validated['role']),
            );
        } catch (\Throwable $exception) {
            Log::error('Finova Operations staff invitation email delivery failed.', [
                'invitation_id' => $invitation->id,
                'exception' => get_class($exception),
            ]);

            return response()->json([
                'error' => 'The invitation was recorded, but its email could not be sent. Retry sending the invitation to issue a fresh link.',
            ], 503);
        }

        return response()->json([
            'data' => [
                'invitation' => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role,
                    'expires_at' => $invitation->expires_at,
                    'status' => 'pending',
                ],
                'message' => 'Invitation email sent.',
            ],
            'errors' => [],
        ], 201);
    }

    public function revokeInvitation(StaffInvitation $staffInvitation): JsonResponse
    {
        if ($staffInvitation->accepted_at) {
            throw ValidationException::withMessages(['invitation' => 'An accepted invitation cannot be revoked. Revoke the staff member’s access instead.']);
        }

        $staffInvitation->delete();

        return response()->json(['data' => ['revoked' => true], 'errors' => []]);
    }

    public function acceptInvitation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'name' => ['required', 'string', 'max:120'],
            'token' => ['required', 'string', 'min:32', 'max:128'],
            'password' => ['required', 'string', 'min:12', 'max:200', 'confirmed'],
        ]);
        $email = strtolower(trim($validated['email']));

        $result = DB::transaction(function () use ($validated, $email, $request): ?array {
            $invitation = StaffInvitation::query()
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->lockForUpdate()
                ->first();
            if (! $invitation
                || $invitation->expires_at->isPast()
                || ! Hash::check($validated['token'], $invitation->token_hash)
                || User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                return null;
            }
            $inviter = User::query()->find($invitation->invited_by_user_id);
            if (! $inviter || ! $inviter->email_verified_at || ! $inviter->hasEnabledTwoFactor()
                || ! $this->isActiveOperationsAdmin($inviter)) {
                return null;
            }

            $user = User::query()->create([
                'name' => trim($validated['name']),
                'email' => $email,
                'password' => $validated['password'],
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $member = StaffMember::query()->create([
                'user_id' => $user->id,
                'role' => $invitation->role,
                'status' => 'pending_mfa',
                'provisioned_by_user_id' => $invitation->invited_by_user_id,
            ]);
            $invitation->forceFill(['accepted_at' => now()])->save();

            StaffAuditEvent::query()->create([
                'actor_user_id' => $invitation->invited_by_user_id,
                'action' => 'control_center.staff_invitation.accept',
                'outcome' => 'allowed',
                'metadata' => [
                    'resource_id' => $member->id,
                    'role' => $member->role,
                    'invited_by_user_id' => (string) $invitation->invited_by_user_id,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 1000),
                'created_at' => now(),
            ]);

            return ['role' => $member->role, 'status' => $member->status];
        });

        if ($result === null) {
            return response()->json([
                'error' => 'This invitation is invalid, expired, already used, or the email already has an account. Contact your Operations administrator for a new invitation.',
            ], 422);
        }

        return response()->json([
            'data' => [
                'role' => $result['role'],
                'status' => $result['status'],
                'message' => 'Your staff account is ready. Sign in and complete MFA setup to activate Operations access.',
            ],
            'errors' => [],
        ], 201);
    }

    private function isActiveOperationsAdmin(User $user): bool
    {
        $email = strtolower((string) $user->email);
        $bootstrapEmails = config('control_center.staff_emails', []);
        $bootstrapRoles = config('control_center.staff_roles', []);
        if (is_array($bootstrapEmails) && in_array($email, $bootstrapEmails, true)) {
            return is_array($bootstrapRoles)
                && ($bootstrapRoles[$email] ?? 'ops_readonly') === 'ops_admin';
        }

        return StaffMember::query()
            ->where('user_id', $user->id)
            ->where('role', 'ops_admin')
            ->where('status', 'active')
            ->exists();
    }

    private function hasOtherActiveAdmin(string $excludedMemberId): bool
    {
        if (StaffMember::query()
            ->join('users', 'users.id', '=', 'control_center_staff.user_id')
            ->where('control_center_staff.status', 'active')
            ->where('control_center_staff.role', 'ops_admin')
            ->where('control_center_staff.id', '!=', $excludedMemberId)
            ->whereNotNull('users.email_verified_at')
            ->whereNotNull('users.two_factor_secret')
            ->where('users.two_factor_secret', '!=', '')
            ->whereNotNull('users.two_factor_confirmed_at')
            ->exists()) {
            return true;
        }

        $bootstrapEmails = config('control_center.staff_emails', []);
        $roles = config('control_center.staff_roles', []);
        $activeBootstrapAdmins = is_array($bootstrapEmails) && is_array($roles)
            ? collect($bootstrapEmails)
                ->filter(fn (string $email): bool => ($roles[strtolower($email)] ?? 'ops_readonly') === 'ops_admin')
                ->values()
                ->all()
            : [];

        return $activeBootstrapAdmins !== [] && User::query()
            ->whereIn(DB::raw('LOWER(email)'), $activeBootstrapAdmins)
            ->whereNotNull('email_verified_at')
            ->whereNotNull('two_factor_secret')
            ->where('two_factor_secret', '!=', '')
            ->whereNotNull('two_factor_confirmed_at')
            ->exists();
    }
}
