<?php

return [
    'require_https' => env('APP_ENV') === 'production',
    'ops_portal_url' => rtrim((string) env('OPS_PORTAL_URL', env('FRONTEND_URL', 'http://localhost:3000')), '/'),
    'ops_allowed_hosts' => array_values(array_filter(array_map(
        static fn (string $host): string => strtolower(trim(explode(':', $host)[0])),
        explode(',', (string) env('OPS_ALLOWED_HOSTS', 'localhost')),
    ))),
    'staff_emails' => array_values(array_filter(array_map(
        static fn (string $email): string => strtolower(trim($email)),
        explode(',', (string) env('CONTROL_CENTER_STAFF_EMAILS', '')),
    ))),
    'staff_roles' => (static function (): array {
        $roles = json_decode((string) env('CONTROL_CENTER_STAFF_ROLES', '{}'), true);
        if (! is_array($roles)) {
            return [];
        }

        $validRoles = ['ops_admin', 'ops_manager', 'ops_sales', 'ops_support', 'ops_readonly'];
        $normalized = [];
        foreach ($roles as $email => $role) {
            if (is_string($email) && is_string($role) && in_array($role, $validRoles, true)) {
                $normalized[strtolower(trim($email))] = $role;
            }
        }

        return $normalized;
    })(),
];
