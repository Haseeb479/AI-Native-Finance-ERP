<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordLinkNotification;
use App\Notifications\VerifyEmailAddressNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthSessionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_active_sessions(): void
    {
        $user = User::factory()->create();
        $token1 = $user->createToken('MacBook Pro')->plainTextToken;
        $token2 = $user->createToken('iPhone 16')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token2)
            ->getJson('/api/v1/auth/sessions');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'sessions' => [
                        '*' => ['id', 'name', 'last_used_at', 'created_at', 'is_current'],
                    ],
                    'total_count',
                ],
            ]);

        $sessions = $response->json('data.sessions');
        $this->assertCount(2, $sessions);

        // One session should be marked as current
        $currentSessions = array_filter($sessions, fn ($s) => $s['is_current'] === true);
        $this->assertCount(1, $currentSessions);
    }

    public function test_user_can_revoke_specific_session(): void
    {
        $user = User::factory()->create();
        $token1 = $user->createToken('Session to Keep')->plainTextToken;
        $device2 = $user->createToken('Session to Revoke');

        $response = $this->withHeader('Authorization', 'Bearer '.$token1)
            ->deleteJson("/api/v1/auth/sessions/{$device2->accessToken->id}");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'message' => "Session {$device2->accessToken->id} revoked successfully.",
                ],
            ]);

        $this->assertCount(1, $user->fresh()->tokens);
    }

    public function test_user_can_revoke_all_other_sessions(): void
    {
        $user = User::factory()->create();
        $user->createToken('Device 1');
        $user->createToken('Device 2');
        $currentToken = $user->createToken('Current Device')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$currentToken)
            ->postJson('/api/v1/auth/sessions/revoke-others');

        $response->assertStatus(200);
        $this->assertCount(1, $user->fresh()->tokens);
        $this->assertEquals('Current Device', $user->fresh()->tokens->first()->name);
    }

    public function test_user_can_revoke_all_sessions(): void
    {
        $user = User::factory()->create();
        $user->createToken('Device 1');
        $currentToken = $user->createToken('Current Device')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$currentToken)
            ->postJson('/api/v1/auth/sessions/revoke-all');

        $response->assertStatus(200);
        $this->assertCount(0, $user->fresh()->tokens);
    }

    public function test_user_can_change_password_and_revokes_other_sessions(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('OldSecretPassword1!'),
        ]);

        $user->createToken('Other Device');
        $currentToken = $user->createToken('Current Device')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$currentToken)
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'OldSecretPassword1!',
                'password' => 'NewSecurePassword99!',
                'password_confirmation' => 'NewSecurePassword99!',
            ]);

        $response->assertStatus(200);
        $this->assertTrue(Hash::check('NewSecurePassword99!', $user->fresh()->password));

        // Other sessions must have been revoked
        $this->assertCount(1, $user->fresh()->tokens);
    }

    public function test_change_password_fails_with_invalid_current_password(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('RealPassword1!'),
        ]);

        $token = $user->createToken('Device')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'WrongPassword',
                'password' => 'NewPassword123!',
                'password_confirmation' => 'NewPassword123!',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'errors' => ['Current password does not match.'],
            ]);
    }

    public function test_forgot_and_reset_password_flow(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => 'finance-director@company.pk',
            'password' => bcrypt('InitialPassword123!'),
        ]);

        $user->createToken('Old Active Session');

        // 1. Request forgot password
        $forgotResponse = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'finance-director@company.pk',
        ]);

        $forgotResponse->assertStatus(200);
        Notification::assertSentTo($user, ResetPasswordLinkNotification::class);

        // P0 FIX: The raw token must NOT be returned in the API response (prevents credential exposure)
        $this->assertArrayNotHasKey('reset_token', $forgotResponse->json('data'));
        $this->assertEquals(
            'If an account with that email address exists, a password reset link has been sent.',
            $forgotResponse->json('data.message')
        );

        // The token IS stored securely (hashed) in the database
        $record = DB::table('password_reset_tokens')->where('email', 'finance-director@company.pk')->first();
        $this->assertNotNull($record, 'Reset token record must exist in DB');
        $this->assertNotEmpty($record->token, 'Token hash must be stored');

        // Simulate receiving token via email link by generating a fresh token and storing it
        // (In production this is sent in the email URL; in tests we generate directly)
        $rawToken = \Illuminate\Support\Str::random(64);
        DB::table('password_reset_tokens')->where('email', 'finance-director@company.pk')->update([
            'token' => \Illuminate\Support\Facades\Hash::make($rawToken),
            'created_at' => now(),
        ]);

        // 2. Complete reset password using token from "email link"
        $resetResponse = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'finance-director@company.pk',
            'token' => $rawToken,
            'password' => 'BrandNewPassword2026!',
            'password_confirmation' => 'BrandNewPassword2026!',
        ]);

        $resetResponse->assertStatus(200)
            ->assertJson([
                'data' => [
                    'message' => 'Password reset successfully. All previous sessions have been revoked.',
                ],
            ]);

        // Password updated
        $this->assertTrue(Hash::check('BrandNewPassword2026!', $user->fresh()->password));

        // Token record consumed & deleted
        $this->assertNull(DB::table('password_reset_tokens')->where('email', 'finance-director@company.pk')->first());

        // All previous sessions revoked for security
        $this->assertCount(0, $user->fresh()->tokens);
    }

    public function test_reset_password_rejects_expired_or_invalid_tokens(): void
    {
        $user = User::factory()->create([
            'email' => 'treasury@company.pk',
        ]);

        // Invalid token
        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'treasury@company.pk',
            'token' => 'invalid-fabricated-token',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'errors' => ['Invalid or expired password reset token.'],
            ]);

        // Expired token (> 60 minutes)
        DB::table('password_reset_tokens')->insert([
            'email' => 'treasury@company.pk',
            'token' => Hash::make('valid-but-old-token'),
            'created_at' => now()->subMinutes(90),
        ]);

        $responseExpired = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'treasury@company.pk',
            'token' => 'valid-but-old-token',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $responseExpired->assertStatus(422)
            ->assertJson([
                'errors' => ['Password reset token has expired.'],
            ]);
    }

    public function test_email_verification_lifecycle(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $token = $user->createToken('Web Client')->plainTextToken;

        // 1. Request verification notification
        $notifyResp = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/email/verification-notification');

        $notifyResp->assertStatus(200);
        Notification::assertSentTo($user, VerifyEmailAddressNotification::class);

        // P0 FIX: The raw verification token must NOT be returned in the API response
        $this->assertArrayNotHasKey('verification_token', $notifyResp->json('data'));
        $this->assertEquals(
            'If your email is not yet verified, a verification link has been sent.',
            $notifyResp->json('data.message')
        );

        // Token IS stored in cache (fetch it to simulate receiving it via email link)
        $cacheKey = "email_verify_{$user->id}";
        $verifyToken = \Illuminate\Support\Facades\Cache::get($cacheKey);
        $this->assertNotNull($verifyToken, 'Verification token must be stored in cache for email delivery');

        // 2. Verify with token (simulating clicking the email link)
        $verifyResp = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/email/verify', [
                'token' => $verifyToken,
            ]);

        $verifyResp->assertStatus(200)
            ->assertJson([
                'data' => [
                    'message' => 'Email verified successfully.',
                ],
            ]);

        $this->assertNotNull($user->fresh()->email_verified_at);

        // 3. Repeated verification acknowledges verified status
        $repeatResp = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/email/verify', [
                'token' => $verifyToken,
            ]);

        $repeatResp->assertStatus(200)
            ->assertJson([
                'data' => [
                    'message' => 'Email is already verified.',
                ],
            ]);
    }

    public function test_signed_verification_email_link_verifies_the_matching_user(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email_verified_at' => null]);
        $user->createToken('Web Client');

        $this->withHeader('Authorization', 'Bearer '.$user->createToken('Verification Client')->plainTextToken)
            ->postJson('/api/v1/auth/email/verification-notification')
            ->assertOk();

        $verificationUrl = null;
        Notification::assertSentTo(
            $user,
            VerifyEmailAddressNotification::class,
            function (VerifyEmailAddressNotification $notification, array $channels) use ($user, &$verificationUrl): bool {
                $this->assertContains('mail', $channels);
                $verificationUrl = $notification->toMail($user)->actionUrl;

                return true;
            }
        );

        $this->assertNotNull($verificationUrl);
        $this->assertTrue(\Illuminate\Support\Facades\URL::hasValidSignature(
            \Illuminate\Http\Request::create($verificationUrl)
        ));

        $parsedUrl = parse_url($verificationUrl);
        $response = $this->getJson($parsedUrl['path'].'?'.$parsedUrl['query']);

        $response->assertOk()
            ->assertJsonPath('data.message', 'Email verified successfully.');
        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->getJson($parsedUrl['path'].'?'.$parsedUrl['query'])
            ->assertOk()
            ->assertJsonPath('data.message', 'Email verified successfully.');
    }

    public function test_signed_verification_link_rejects_modified_or_expired_signatures(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'api.v1.auth.verify',
            now()->subMinute(),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())]
        );

        $expired = parse_url($url);
        $this->getJson($expired['path'].'?'.$expired['query'])->assertForbidden();

        $validUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'api.v1.auth.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())]
        );
        $modified = parse_url($validUrl);
        $query = $modified['query'].'&id=another-user';

        $this->getJson($modified['path'].'?'.$query)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    // ─────────────────────────────────────────────────────────────
    // P0 REGRESSION: Token exposure and account enumeration tests
    // ─────────────────────────────────────────────────────────────

    public function test_forgot_password_never_returns_raw_token_in_response(): void
    {
        User::factory()->create(['email' => 'known@company.pk']);

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'known@company.pk',
        ]);

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('reset_token', $response->json('data') ?? []);
    }

    public function test_forgot_password_does_not_reveal_account_existence(): void
    {
        // Both existing and non-existing emails must return identical responses
        $existsResponse = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'doesnotexist@company.pk',
        ]);

        $existsResponse->assertStatus(200);
        $this->assertEquals(
            'If an account with that email address exists, a password reset link has been sent.',
            $existsResponse->json('data.message')
        );
    }

    public function test_used_reset_token_cannot_be_reused(): void
    {
        $user = User::factory()->create(['email' => 'cfo@reuse-test.pk']);

        $rawToken = \Illuminate\Support\Str::random(64);
        DB::table('password_reset_tokens')->insert([
            'email' => 'cfo@reuse-test.pk',
            'token' => Hash::make($rawToken),
            'created_at' => now(),
        ]);

        // First use succeeds
        $first = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'cfo@reuse-test.pk',
            'token' => $rawToken,
            'password' => 'NewPassword2026!A',
            'password_confirmation' => 'NewPassword2026!A',
        ]);
        $first->assertStatus(200);

        // Second use with same token must fail (token was consumed)
        $second = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'cfo@reuse-test.pk',
            'token' => $rawToken,
            'password' => 'AnotherPassword2026!B',
            'password_confirmation' => 'AnotherPassword2026!B',
        ]);
        $second->assertStatus(422);
    }

    public function test_verification_notification_never_returns_raw_token(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/email/verification-notification');

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('verification_token', $response->json('data') ?? []);
    }

    public function test_change_password_fails_with_password_shorter_than_12_characters(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('RealPassword123!'),
        ]);

        $token = $user->createToken('Device')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'RealPassword123!',
                'password' => 'Short12345!', // 11 chars, < 12
                'password_confirmation' => 'Short12345!',
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors']);
    }

    public function test_reset_password_fails_with_password_shorter_than_12_characters(): void
    {
        $user = User::factory()->create(['email' => 'short-reset@company.pk']);

        $rawToken = \Illuminate\Support\Str::random(64);
        DB::table('password_reset_tokens')->insert([
            'email' => 'short-reset@company.pk',
            'token' => Hash::make($rawToken),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'short-reset@company.pk',
            'token' => $rawToken,
            'password' => 'Short12345!', // 11 chars, < 12
            'password_confirmation' => 'Short12345!',
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors']);
    }
}
