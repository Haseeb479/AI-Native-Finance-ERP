<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthThrottlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_throttles_and_locks_account_after_consecutive_failures(): void
    {
        $user = User::factory()->create([
            'email' => 'victim@example.com',
            'password' => Hash::make('CorrectPassword123!'),
        ]);

        // Attempt 5 incorrect logins
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/v1/auth/login', [
                'email' => 'victim@example.com',
                'password' => 'WrongPassword!',
            ]);
            $response->assertStatus(401);
        }

        // 6th attempt should be blocked by account-level progressive lockout (429)
        $lockedResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'victim@example.com',
            'password' => 'CorrectPassword123!',
        ]);

        $lockedResponse->assertStatus(429);
        $this->assertStringContainsString('Account temporarily locked', $lockedResponse->json('errors.0'));
        $this->assertTrue($lockedResponse->headers->has('Retry-After'));

        // Clearing lockout allows successful login
        Cache::forget('auth_throttle_account:victim@example.com');

        $successResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'victim@example.com',
            'password' => 'CorrectPassword123!',
        ]);

        $successResponse->assertStatus(200);
        $this->assertNotNull($successResponse->json('data.token'));
    }
}
