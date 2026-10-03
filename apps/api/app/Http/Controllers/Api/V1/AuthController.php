<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Register a new user and issue an API authentication token.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'created_at' => $user->created_at?->toIso8601String(),
                ],
                'token' => $token,
                'token_type' => 'Bearer',
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
            'errors' => [],
        ], 201);
    }

    /**
     * Authenticate user credentials and return an API token or 2FA challenge.
     * P1-01: Progressive backoff, per-account failure lockout.
     * P1-06: TOTP MFA challenge gate.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $email = strtolower(trim($validated['email']));

        $accountThrottleKey = "auth_throttle_account:{$email}";
        $attempts = (int) \Illuminate\Support\Facades\Cache::get($accountThrottleKey, 0);

        if ($attempts >= 5) {
            $lockoutSeconds = min(300, 30 * pow(2, $attempts - 5));
            return response()->json([
                'data' => null,
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'retry_after' => $lockoutSeconds,
                ],
                'errors' => ["Account temporarily locked due to too many failed login attempts. Retry after {$lockoutSeconds} seconds."],
            ], 429, ['Retry-After' => $lockoutSeconds]);
        }

        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            \Illuminate\Support\Facades\Cache::put($accountThrottleKey, $attempts + 1, now()->addMinutes(15));
            return response()->json([
                'data' => null,
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'remaining_attempts' => max(0, 5 - ($attempts + 1)),
                ],
                'errors' => ['Invalid email or password.'],
            ], 401);
        }

        // Reset failed counter upon valid credentials
        \Illuminate\Support\Facades\Cache::forget($accountThrottleKey);

        // Check if TOTP Multi-Factor Authentication is enabled (P1-06)
        if ($user->hasEnabledTwoFactor()) {
            $challengeToken = \Illuminate\Support\Str::random(64);
            \Illuminate\Support\Facades\Cache::put("mfa_challenge_{$challengeToken}", [
                'user_id' => $user->id,
                'device_name' => $validated['device_name'] ?? 'api_client',
            ], now()->addMinutes(5));

            return response()->json([
                'data' => [
                    'mfa_required' => true,
                    'mfa_challenge_token' => $challengeToken,
                    'user' => [
                        'id' => $user->id,
                        'email' => $user->email,
                    ],
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                ],
                'errors' => [],
            ], 200);
        }

        $deviceName = $validated['device_name'] ?? 'api_client';
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'token' => $token,
                'token_type' => 'Bearer',
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
            'errors' => [],
        ], 200);
    }

    /**
     * Complete MFA challenge for login (P1-06).
     */
    public function challengeLogin(Request $request): JsonResponse
    {
        $request->validate([
            'mfa_challenge_token' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        $challengeData = \Illuminate\Support\Facades\Cache::get("mfa_challenge_{$request->mfa_challenge_token}");
        if (! $challengeData) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => ['Invalid or expired MFA challenge session. Please log in again.'],
            ], 422);
        }

        $user = User::find($challengeData['user_id']);
        if (! $user) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => ['User account not found.'],
            ], 404);
        }

        $mfaService = app(\App\Domain\Security\Services\MfaService::class);
        $code = trim($request->code);
        $verified = false;

        if (strlen($code) === 6 && ctype_digit($code)) {
            $verified = $mfaService->verifyCode($user->two_factor_secret, $code);
        } else {
            $verified = $mfaService->verifyAndConsumeRecoveryCode($user, $code);
        }

        if (! $verified) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => ['Invalid two-factor authentication code or recovery code.'],
            ], 422);
        }

        \Illuminate\Support\Facades\Cache::forget("mfa_challenge_{$request->mfa_challenge_token}");

        $deviceName = $challengeData['device_name'] ?? 'api_client';
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'token' => $token,
                'token_type' => 'Bearer',
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
            'errors' => [],
        ], 200);
    }


    /**
     * Revoke current user's active API token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'data' => [
                'message' => 'Logged out successfully.',
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
            'errors' => [],
        ], 200);
    }

    /**
     * Get authenticated user profile.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                    'created_at' => $user->created_at?->toIso8601String(),
                ],
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
            'errors' => [],
        ], 200);
    }

    /**
     * List all active sessions/tokens for authenticated user (P1-03).
     */
    public function sessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentTokenId = $user->currentAccessToken()?->id;

        $tokens = $user->tokens()
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($token) use ($currentTokenId) {
                return [
                    'id' => $token->id,
                    'name' => $token->name,
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'created_at' => $token->created_at?->toIso8601String(),
                    'is_current' => $token->id === $currentTokenId,
                ];
            });

        return response()->json([
            'data' => [
                'sessions' => $tokens,
                'total_count' => $tokens->count(),
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
            'errors' => [],
        ], 200);
    }

    /**
     * Revoke a specific session/token by ID (P1-03).
     */
    public function revokeSession(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $deleted = $user->tokens()->where('id', $id)->delete();

        if (! $deleted) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => ['Session not found or already revoked.'],
            ], 404);
        }

        return response()->json([
            'data' => [
                'message' => "Session {$id} revoked successfully.",
            ],
            'meta' => ['timestamp' => now()->toIso8601String()],
            'errors' => [],
        ], 200);
    }

    /**
     * Revoke all other sessions except the current one (P1-03).
     */
    public function revokeOtherSessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentId = $user->currentAccessToken()?->id;

        $user->tokens()->where('id', '!=', $currentId)->delete();

        return response()->json([
            'data' => [
                'message' => 'All other sessions have been revoked successfully.',
            ],
            'meta' => ['timestamp' => now()->toIso8601String()],
            'errors' => [],
        ], 200);
    }

    /**
     * Revoke all active sessions including current one (P1-03).
     */
    public function revokeAllSessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->tokens()->delete();

        return response()->json([
            'data' => [
                'message' => 'All sessions have been revoked successfully.',
            ],
            'meta' => ['timestamp' => now()->toIso8601String()],
            'errors' => [],
        ], 200);
    }

    /**
     * Change authenticated user password (P1-05).
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => ['Current password does not match.'],
            ], 422);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        // Revoke other sessions to prevent unauthorized persistence
        $currentId = $user->currentAccessToken()?->id;
        $user->tokens()->where('id', '!=', $currentId)->delete();

        return response()->json([
            'data' => [
                'message' => 'Password changed successfully. Other sessions have been signed out.',
            ],
            'meta' => ['timestamp' => now()->toIso8601String()],
            'errors' => [],
        ], 200);
    }

    /**
     * Initiate password reset request (P1-05).
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $request->email)->first();

        $token = null;
        if ($user) {
            $token = \Illuminate\Support\Str::random(64);
            \Illuminate\Support\Facades\DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $request->email],
                ['token' => Hash::make($token), 'created_at' => now()]
            );
        }

        // Security: If user exists, queue the reset email. We never return the token in the API
        // response — it is delivered exclusively through the verified email channel to prevent
        // credential exposure via API logs, browser history, proxy traces, or screenshots.
        if ($user) {
            // TODO: dispatch(new SendPasswordResetEmail($user->email, $token));
            // Until email service is wired, token is only stored (hashed) in DB — not returned here.
        }

        // Always return identical generic response regardless of whether email exists (prevents enumeration)
        return response()->json([
            'data' => [
                'message' => 'If an account with that email address exists, a password reset link has been sent.',
            ],
            'meta' => ['timestamp' => now()->toIso8601String()],
            'errors' => [],
        ], 200);
    }

    /**
     * Complete password reset using verified token (P1-05).
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $record = \Illuminate\Support\Facades\DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (! $record || ! Hash::check($request->token, $record->token)) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => ['Invalid or expired password reset token.'],
            ], 422);
        }

        // 60-minute token expiry check
        if (\Carbon\Carbon::parse($record->created_at)->addMinutes(60)->isPast()) {
            \Illuminate\Support\Facades\DB::table('password_reset_tokens')->where('email', $request->email)->delete();

            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => ['Password reset token has expired.'],
            ], 422);
        }

        $user = User::where('email', $request->email)->first();
        if (! $user) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => ['User not found.'],
            ], 404);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        \Illuminate\Support\Facades\DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        // Revoke all existing sessions after password reset
        $user->tokens()->delete();

        return response()->json([
            'data' => [
                'message' => 'Password reset successfully. All previous sessions have been revoked.',
            ],
            'meta' => ['timestamp' => now()->toIso8601String()],
            'errors' => [],
        ], 200);
    }

    /**
     * Request email verification notification (P1-05).
     */
    public function sendVerificationNotification(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'data' => ['message' => 'Email is already verified.'],
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => [],
            ], 200);
        }

        $verificationCode = \Illuminate\Support\Str::random(32);
        \Illuminate\Support\Facades\Cache::put("email_verify_{$user->id}", $verificationCode, now()->addMinutes(60));

        // Security: Store the verification code and send it via the email channel only.
        // Never return it in the API response to prevent token exposure via logs/proxies.
        // TODO: dispatch(new SendVerificationEmail($user, $verificationCode));

        return response()->json([
            'data' => [
                'message' => 'If your email is not yet verified, a verification link has been sent.',
            ],
            'meta' => ['timestamp' => now()->toIso8601String()],
            'errors' => [],
        ], 200);
    }

    /**
     * Verify email with verification code/token (P1-05).
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
        ]);

        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'data' => ['message' => 'Email is already verified.'],
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => [],
            ], 200);
        }

        $cachedToken = \Illuminate\Support\Facades\Cache::get("email_verify_{$user->id}");

        if (! $cachedToken || $cachedToken !== $request->token) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => ['Invalid or expired verification token.'],
            ], 422);
        }

        $user->markEmailAsVerified();
        \Illuminate\Support\Facades\Cache::forget("email_verify_{$user->id}");

        return response()->json([
            'data' => [
                'message' => 'Email verified successfully.',
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            ],
            'meta' => ['timestamp' => now()->toIso8601String()],
            'errors' => [],
        ], 200);
    }
}
