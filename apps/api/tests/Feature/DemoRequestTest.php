<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_request_is_validated_and_saved_without_claiming_an_appointment(): void
    {
        $this->postJson('/api/v1/demo-requests', [
            'first_name' => 'Sam',
            'last_name' => 'Accountant',
            'email' => 'sam@example.test',
            'company_name' => 'Example Finance',
            'company_size' => '11-50',
            'role' => 'Controller',
            'referral_source' => 'Referral',
            'message' => 'Interested in Pakistan tax workflows.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.message', 'Your demo request has been received. Our team will follow up using the work email you provided.');

        $this->assertDatabaseHas('demo_requests', [
            'first_name' => 'Sam',
            'email' => 'sam@example.test',
            'company_name' => 'Example Finance',
            'status' => 'new',
        ]);
    }

    public function test_demo_request_rejects_invalid_company_size_and_email(): void
    {
        $this->postJson('/api/v1/demo-requests', [
            'first_name' => 'Sam',
            'last_name' => 'Accountant',
            'email' => 'invalid',
            'company_name' => 'Example Finance',
            'company_size' => 'lots',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.0.details.email.0', 'The email field must be a valid email address.')
            ->assertJsonPath('errors.0.details.company_size.0', 'The selected company size is invalid.');

        $this->assertDatabaseCount('demo_requests', 0);
    }
}
