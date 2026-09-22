<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Define Master Permissions (TASKS.md Section 4)
        $permissions = [
            // Organization
            ['name' => 'organization.view', 'group' => 'organization', 'description' => 'View organization settings and entities'],
            ['name' => 'organization.manage', 'group' => 'organization', 'description' => 'Update organization settings and entities'],

            // Users
            ['name' => 'users.view', 'group' => 'users', 'description' => 'View organization members'],
            ['name' => 'users.manage', 'group' => 'users', 'description' => 'Invite, edit, and remove members'],

            // Accounting & General Ledger
            ['name' => 'accounting.view', 'group' => 'accounting', 'description' => 'View Chart of Accounts and General Ledger'],
            ['name' => 'accounting.journal.create', 'group' => 'accounting', 'description' => 'Create draft journal entries'],
            ['name' => 'accounting.journal.approve', 'group' => 'accounting', 'description' => 'Approve journal entries for posting'],
            ['name' => 'accounting.journal.post', 'group' => 'accounting', 'description' => 'Post immutable journal entries to General Ledger'],

            // Sales & Accounts Receivable
            ['name' => 'sales.view', 'group' => 'sales', 'description' => 'View customers and sales invoices'],
            ['name' => 'sales.invoice.create', 'group' => 'sales', 'description' => 'Draft sales invoices'],
            ['name' => 'sales.invoice.approve', 'group' => 'sales', 'description' => 'Approve sales invoices'],
            ['name' => 'sales.invoice.post', 'group' => 'sales', 'description' => 'Post invoices to ledger'],

            // Purchases & Accounts Payable
            ['name' => 'purchases.view', 'group' => 'purchases', 'description' => 'View vendors and purchase bills'],
            ['name' => 'purchases.bill.create', 'group' => 'purchases', 'description' => 'Draft vendor bills'],
            ['name' => 'purchases.bill.approve', 'group' => 'purchases', 'description' => 'Approve vendor bills'],
            ['name' => 'purchases.bill.post', 'group' => 'purchases', 'description' => 'Post vendor bills to ledger'],

            // Banking & Reconciliation
            ['name' => 'banking.view', 'group' => 'banking', 'description' => 'View bank accounts and statement lines'],
            ['name' => 'banking.reconcile', 'group' => 'banking', 'description' => 'Reconcile bank transactions'],

            // Reports
            ['name' => 'reports.view', 'group' => 'reports', 'description' => 'View P&L, Balance Sheet, and Trial Balance'],
            ['name' => 'reports.export', 'group' => 'reports', 'description' => 'Export financial statements to PDF/Excel'],

            // AI Capabilities
            ['name' => 'ai.use', 'group' => 'ai', 'description' => 'Use AI copilot for categorization and inquiries'],
            ['name' => 'ai.execute', 'group' => 'ai', 'description' => 'Execute approved AI draft workflows'],
        ];

        foreach ($permissions as $perm) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $perm['name']],
                array_merge($perm, ['created_at' => now(), 'updated_at' => now()])
            );
        }

        // 2. Define Master Roles
        $roles = [
            'owner' => ['label' => 'Owner', 'description' => 'Full ownership and root administrative access'],
            'admin' => ['label' => 'Administrator', 'description' => 'Can manage company settings, users, and all operations'],
            'finance_manager' => ['label' => 'Finance Manager', 'description' => 'Can approve journals, bills, invoices, and run financial operations'],
            'accountant' => ['label' => 'Accountant', 'description' => 'Can create and post journals, reconcile banking, and generate statements'],
            'staff' => ['label' => 'Staff / Executive', 'description' => 'Can create draft bills, view assigned operations, and draft expenses'],
            'auditor' => ['label' => 'Auditor (Read-Only)', 'description' => 'Strict read-only access across all financial ledgers and reports'],
        ];

        foreach ($roles as $name => $meta) {
            DB::table('roles')->updateOrInsert(
                ['name' => $name],
                [
                    'label' => $meta['label'],
                    'description' => $meta['description'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // 3. Map Roles to Permissions
        $allPermIds = DB::table('permissions')->pluck('id', 'name')->toArray();
        $roleIds = DB::table('roles')->pluck('id', 'name')->toArray();

        $rolePermissionsMap = [
            'owner' => array_keys($allPermIds), // Owner has ALL permissions
            'admin' => [
                'organization.view', 'organization.manage',
                'users.view', 'users.manage',
                'accounting.view', 'sales.view', 'purchases.view',
                'banking.view', 'reports.view', 'reports.export', 'ai.use',
            ],
            'finance_manager' => [
                'organization.view', 'users.view',
                'accounting.view', 'accounting.journal.create', 'accounting.journal.approve', 'accounting.journal.post',
                'sales.view', 'sales.invoice.create', 'sales.invoice.approve', 'sales.invoice.post',
                'purchases.view', 'purchases.bill.create', 'purchases.bill.approve', 'purchases.bill.post',
                'banking.view', 'banking.reconcile',
                'reports.view', 'reports.export',
                'ai.use', 'ai.execute',
            ],
            'accountant' => [
                'organization.view', 'users.view',
                'accounting.view', 'accounting.journal.create', 'accounting.journal.post',
                'sales.view', 'sales.invoice.create', 'sales.invoice.post',
                'purchases.view', 'purchases.bill.create', 'purchases.bill.post',
                'banking.view', 'banking.reconcile',
                'reports.view', 'reports.export',
                'ai.use',
            ],
            'staff' => [
                'organization.view',
                'accounting.view',
                'sales.view', 'sales.invoice.create',
                'purchases.view', 'purchases.bill.create',
                'ai.use',
            ],
            'auditor' => [
                'organization.view', 'users.view',
                'accounting.view',
                'sales.view',
                'purchases.view',
                'banking.view',
                'reports.view', 'reports.export',
                'ai.use',
            ],
        ];

        foreach ($rolePermissionsMap as $roleName => $permNames) {
            $roleId = $roleIds[$roleName];
            foreach ($permNames as $permName) {
                if (isset($allPermIds[$permName])) {
                    DB::table('role_permission')->updateOrInsert([
                        'role_id' => $roleId,
                        'permission_id' => $allPermIds[$permName],
                    ]);
                }
            }
        }
    }
}
