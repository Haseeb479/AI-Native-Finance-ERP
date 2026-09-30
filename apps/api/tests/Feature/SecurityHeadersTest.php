<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    /**
     * Test that all critical HTTP enterprise security headers are returned.
     * P1-30: HSTS, CSP, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, X-Frame-Options.
     */
    public function test_api_responses_contain_hardened_security_headers(): void
    {
        $response = $this->get('/api/v1/health');

        $response->assertStatus(200);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
        $this->assertTrue($response->headers->has('Permissions-Policy'));
        $this->assertTrue($response->headers->has('Content-Security-Policy'));
        
        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
    }
}
