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
     * Authenticate user credentials and return an API token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                ],
                'errors' => ['Invalid email or password.'],
            ], 401);
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
            'password' => ['required', 'string', 'min:8', 'confirmed'],
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

        return response()->json([
            'data' => [
                'message' => 'If an account exists with this email, a password reset token has been generated.',
                'reset_token' => $token, // Provided in response for API client consumption & testing
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
            'password' => ['required', 'string', 'min:8', 'confirmed'],
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

        return response()->json([
            'data' => [
                'message' => 'Verification token generated successfully.',
                'verification_token' => $verificationCode,
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
