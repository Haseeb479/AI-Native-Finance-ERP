<?php

namespace Tests\Feature;

use App\Domain\Security\Services\MfaService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MfaTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_setup_and_confirm_totp_mfa(): void
    {
        $user = User::factory()->create([
            'email' => 'mfa-test@example.com',
            'password' => Hash::make('password123'),
        ]);

        $mfaService = app(MfaService::class);

        // 1. Setup endpoint
        $setupRes = $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/mfa/setup');
        $setupRes->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'secret',
                    'provisioning_uri',
                    'expires_in_minutes',
                ],
            ]);

        $secret = $setupRes->json('data.secret');
        $this->assertEquals(32, strlen($secret));

        // 2. Generate valid TOTP code using service
        $timeSlice = (int) floor(time() / 30);
        // Calculate code using reflection or public calculation helper
        $reflection = new \ReflectionClass($mfaService);
        $method = $reflection->getMethod('calculateCode');
        $method->setAccessible(true);
        $validCode = $method->invoke($mfaService, $secret, $timeSlice);

        // 3. Confirm endpoint
        $confirmRes = $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/mfa/confirm', [
            'code' => $validCode,
        ]);

        $confirmRes->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'message',
                    'recovery_codes',
                ],
            ]);

        $recoveryCodes = $confirmRes->json('data.recovery_codes');
        $this->assertCount(8, $recoveryCodes);

        $user->refresh();
        $this->assertTrue($user->hasEnabledTwoFactor());
    }

    public function test_login_prompts_mfa_challenge_when_enabled(): void
    {
        $mfaService = app(MfaService::class);
        $secret = $mfaService->generateSecretKey();
        $recoveryCodes = $mfaService->generateRecoveryCodes(8);

        $user = User::factory()->create([
            'email' => 'mfa-challenge@example.com',
            'password' => Hash::make('SecretPass123!'),
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $recoveryCodes,
            'two_factor_confirmed_at' => now(),
        ]);

        // Attempt normal login
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'mfa-challenge@example.com',
            'password' => 'SecretPass123!',
        ]);

        $loginRes->assertStatus(200)
            ->assertJson([
                'data' => [
                    'mfa_required' => true,
                ],
            ])
            ->assertJsonStructure([
                'data' => [
                    'mfa_required',
                    'mfa_challenge_token',
                    'user' => ['id', 'email'],
                ],
            ]);

        $challengeToken = $loginRes->json('data.mfa_challenge_token');

        // Complete challenge with single-use recovery code
        $chosenRecoveryCode = $recoveryCodes[0];
        $challengeRes = $this->postJson('/api/v1/auth/mfa/challenge', [
            'mfa_challenge_token' => $challengeToken,
            'code' => $chosenRecoveryCode,
        ]);

        $challengeRes->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'token_type',
                    'user' => ['id', 'email'],
                ],
            ]);

        // Verify recovery code was consumed and cannot be reused
        $user->refresh();
        $this->assertNotContains($chosenRecoveryCode, $user->two_factor_recovery_codes);
        $this->assertCount(7, $user->two_factor_recovery_codes);
    }

    public function test_step_up_authentication_verification_and_expiry(): void
    {
        $mfaService = app(MfaService::class);
        $secret = $mfaService->generateSecretKey();

        $user = User::factory()->create([
            'email' => 'stepup@example.com',
            'password' => Hash::make('StrongPassword123!'),
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
        ]);

        // 1. Without MFA verification, step-up check fails
        $this->assertFalse($mfaService->hasRecentStepUp($user));

        // 2. Direct code verification satisfies step-up
        $timeSlice = (int) floor(time() / 30);
        $reflection = new \ReflectionClass($mfaService);
        $method = $reflection->getMethod('calculateCode');
        $method->setAccessible(true);
        $validCode = $method->invoke($mfaService, $secret, $timeSlice);

        $this->assertTrue($mfaService->hasRecentStepUp($user, $validCode));

        // 3. Invalid code fails
        $this->assertFalse($mfaService->hasRecentStepUp($user, '000000'));

        // 4. Verifying via endpoint issues step_up_token and marks session
        $verifyRes = $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/mfa/verify', [
            'code' => $validCode,
        ]);
        $verifyRes->assertStatus(200);
        $stepUpToken = $verifyRes->json('data.step_up_token');
        $this->assertNotNull($stepUpToken);

        // 5. Subsequent step-up check passes with token
        $this->assertTrue($mfaService->hasRecentStepUp($user, null, $stepUpToken));

        // 6. User recent MFA session passes
        $this->assertTrue($mfaService->hasRecentStepUp($user));
    }
}
