<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Security\Services\MfaService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class MfaController extends Controller
{
    public function __construct(
        protected MfaService $mfaService
    ) {}

    /**
     * Initiate TOTP MFA setup by generating a secret and provisioning URI (P1-06).
     */
    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasEnabledTwoFactor()) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => ['Two-factor authentication is already enabled for this account.'],
            ], 400);
        }

        $secret = $this->mfaService->generateSecretKey();
        $qrCodeUrl = $this->mfaService->getQrCodeUrl($user, $secret);

        // Cache pending secret for 15 minutes awaiting user confirmation
        Cache::put("mfa_pending_{$user->id}", $secret, now()->addMinutes(15));

        return response()->json([
            'data' => [
                'secret' => $secret,
                'provisioning_uri' => $qrCodeUrl,
                'expires_in_minutes' => 15,
            ],
            'meta' => ['timestamp' => now()->toIso8601String()],
            'errors' => [],
        ], 200);
    }

    /**
     * Confirm TOTP setup by verifying the first code and issuing recovery codes (P1-06).
     */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $user = $request->user();
        $pendingSecret = Cache::get("mfa_pending_{$user->id}");

        if (! $pendingSecret) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => ['MFA setup session expired. Please initiate setup again.'],
            ], 422);
        }

        if (! $this->mfaService->verifyCode($pendingSecret, $request->code)) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => ['Invalid two-factor authentication code.'],
            ], 422);
        }

        // Generate recovery codes
        $recoveryCodes = $this->mfaService->generateRecoveryCodes(8);

        // Commit MFA configuration to user record
        $user->two_factor_secret = $pendingSecret;
        $user->two_factor_recovery_codes = $recoveryCodes;
        $user->two_factor_confirmed_at = now();
        $user->save();

        Cache::forget("mfa_pending_{$user->id}");

        return response()->json([
            'data' => [
                'message' => 'Two-factor authentication enabled successfully.',
                'recovery_codes' => $recoveryCodes,
            ],
            'meta' => ['timestamp' => now()->toIso8601String()],
            'errors' => [],
        ], 200);
    }

    /**
     * Verify TOTP code or single-use recovery code (P1-06).
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! $user->hasEnabledTwoFactor()) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => ['Two-factor authentication is not enabled on this account.'],
            ], 400);
        }

        $code = trim($request->code);
        $verified = false;
        $method = 'totp';

        // Check 6-digit TOTP
        if (strlen($code) === 6 && ctype_digit($code)) {
            $verified = $this->mfaService->verifyCode($user->two_factor_secret, $code);
        } else {
            // Check recovery code
            $verified = $this->mfaService->verifyAndConsumeRecoveryCode($user, $code);
            $method = 'recovery_code';
        }

        if (! $verified) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => ['Invalid two-factor authentication code or recovery code.'],
            ], 422);
        }

        $stepUpToken = $this->mfaService->recordStepUp($user);

        return response()->json([
            'data' => [
                'verified' => true,
                'method' => $method,
                'step_up_token' => $stepUpToken,
                'message' => 'Two-factor authentication verified successfully.',
            ],
            'meta' => ['timestamp' => now()->toIso8601String()],
            'errors' => [],
        ], 200);
    }

    /**
     * Disable TOTP MFA (requires password confirmation) (P1-06).
     */
    public function disable(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! Hash::check($request->password, $user->password)) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => ['Incorrect account password.'],
            ], 422);
        }

        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->save();

        return response()->json([
            'data' => [
                'message' => 'Two-factor authentication disabled successfully.',
            ],
            'meta' => ['timestamp' => now()->toIso8601String()],
            'errors' => [],
        ], 200);
    }

    /**
     * Regenerate new recovery codes (requires current password) (P1-06).
     */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! $user->hasEnabledTwoFactor()) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => ['Two-factor authentication is not enabled.'],
            ], 400);
        }

        if (! Hash::check($request->password, $user->password)) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => ['Incorrect account password.'],
            ], 422);
        }

        $newCodes = $this->mfaService->generateRecoveryCodes(8);
        $user->two_factor_recovery_codes = $newCodes;
        $user->save();

        return response()->json([
            'data' => [
                'message' => 'Recovery codes regenerated successfully.',
                'recovery_codes' => $newCodes,
            ],
            'meta' => ['timestamp' => now()->toIso8601String()],
            'errors' => [],
        ], 200);
    }
}
