<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
        $rawToken = $forgotResponse->json('data.reset_token');
        $this->assertNotNull($rawToken);

        // Verify record in password_reset_tokens
        $record = DB::table('password_reset_tokens')->where('email', 'finance-director@company.pk')->first();
        $this->assertNotNull($record);
        $this->assertTrue(Hash::check($rawToken, $record->token));

        // 2. Complete reset password
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
        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $token = $user->createToken('Web Client')->plainTextToken;

        // 1. Request verification notification
        $notifyResp = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/email/verification-notification');

        $notifyResp->assertStatus(200);
        $verifyToken = $notifyResp->json('data.verification_token');
        $this->assertNotNull($verifyToken);

        // 2. Verify with token
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
}
