<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_verified_google_identity_signs_user_in_and_verifies_email(): void
    {
        config(['services.google.client_id' => 'finova-client-id']);
        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => 'finova-client-id',
                'iss' => 'https://accounts.google.com',
                'sub' => 'google-subject-123',
                'email' => 'Finance.User@example.test',
                'email_verified' => 'true',
                'name' => 'Finance User',
                'exp' => (string) now()->addMinutes(10)->timestamp,
            ]),
        ]);

        $response = $this->postJson('/api/v1/auth/google', ['credential' => 'google-id-token']);

        $response->assertOk()
            ->assertJsonPath('data.user.email', 'finance.user@example.test')
            ->assertJsonPath('data.user.name', 'Finance User')
            ->assertJsonPath('data.token_type', 'Bearer');

        $this->assertDatabaseHas('users', [
            'email' => 'finance.user@example.test',
            'email_verified_at' => now()->toDateTimeString(),
        ]);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        Http::assertSent(fn ($request) => parse_url($request->url(), PHP_URL_PATH) === '/tokeninfo'
            && $request['id_token'] === 'google-id-token');
    }

    public function test_invalid_google_audience_is_rejected_without_creating_user(): void
    {
        config(['services.google.client_id' => 'finova-client-id']);
        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => 'attacker-client-id',
                'iss' => 'accounts.google.com',
                'sub' => 'google-subject-123',
                'email' => 'attacker@example.test',
                'email_verified' => 'true',
                'exp' => (string) now()->addMinutes(10)->timestamp,
            ]),
        ]);

        $this->postJson('/api/v1/auth/google', ['credential' => 'forged-token'])
            ->assertUnauthorized()
            ->assertJsonPath('errors.0.code', 'INVALID_GOOGLE_CREDENTIAL');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_google_sign_in_cannot_bypass_two_factor_authentication(): void
    {
        config(['services.google.client_id' => 'finova-client-id']);
        $user = User::factory()->create([
            'email' => 'mfa@example.test',
            'two_factor_secret' => 'encrypted-secret',
            'two_factor_confirmed_at' => now(),
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => 'finova-client-id',
                'iss' => 'accounts.google.com',
                'sub' => 'google-subject-123',
                'email' => $user->email,
                'email_verified' => 'true',
                'exp' => (string) now()->addMinutes(10)->timestamp,
            ]),
        ]);

        $this->postJson('/api/v1/auth/google', ['credential' => 'google-id-token'])
            ->assertForbidden()
            ->assertJsonPath('errors.0.code', 'MFA_REQUIRED');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_google_sign_in_fails_closed_when_provider_is_not_configured(): void
    {
        config(['services.google.client_id' => null]);

        $this->postJson('/api/v1/auth/google', ['credential' => 'google-id-token'])
            ->assertStatus(503)
            ->assertJsonPath('errors.0.code', 'GOOGLE_SIGN_IN_UNAVAILABLE');
    }
}
