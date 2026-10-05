<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class GoogleAuthenticationController extends Controller
{
    public function authenticate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'credential' => ['required', 'string', 'max:8192'],
        ]);

        $clientId = config('services.google.client_id');
        if (! is_string($clientId) || $clientId === '') {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => [['code' => 'GOOGLE_SIGN_IN_UNAVAILABLE', 'message' => 'Google sign-in is not configured.']],
            ], 503);
        }

        try {
            $response = Http::timeout(5)->get('https://oauth2.googleapis.com/tokeninfo', [
                'id_token' => $validated['credential'],
            ]);
        } catch (Throwable $exception) {
            Log::warning('Google identity verification request failed.', [
                'exception' => get_class($exception),
            ]);

            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => [['code' => 'GOOGLE_IDENTITY_UNAVAILABLE', 'message' => 'Google sign-in is temporarily unavailable.']],
            ], 503);
        }

        $identity = $response->successful() ? $response->json() : null;
        $emailVerified = is_array($identity)
            && ($identity['email_verified'] ?? null) === 'true';
        $expiresAt = is_array($identity) ? filter_var($identity['exp'] ?? null, FILTER_VALIDATE_INT) : false;
        $validIdentity = is_array($identity)
            && hash_equals($clientId, (string) ($identity['aud'] ?? ''))
            && in_array($identity['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)
            && is_string($identity['sub'] ?? null)
            && ($identity['sub'] ?? '') !== ''
            && filter_var($identity['email'] ?? null, FILTER_VALIDATE_EMAIL)
            && $emailVerified
            && $expiresAt !== false
            && $expiresAt > now()->timestamp;

        if (! $validIdentity) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => [['code' => 'INVALID_GOOGLE_CREDENTIAL', 'message' => 'The Google sign-in credential is invalid or expired.']],
            ], 401);
        }

        $email = Str::lower($identity['email']);
        $user = User::query()->where('email', $email)->first();

        if ($user?->hasEnabledTwoFactor()) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => [['code' => 'MFA_REQUIRED', 'message' => 'Use your password and two-factor code to sign in to this account.']],
            ], 403);
        }

        if (! $user) {
            $user = User::query()->create([
                'name' => trim((string) ($identity['name'] ?? $email)) ?: $email,
                'email' => $email,
                'password' => Str::random(64),
            ]);
        }

        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $token = $user->createToken('web_google_signin')->plainTextToken;

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
            'meta' => ['timestamp' => now()->toIso8601String()],
            'errors' => [],
        ]);
    }
}
