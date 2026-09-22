<?php

namespace Database\Seeders;

use App\Domain\Accounting\ChartOfAccounts\Models\AccountGroup;
use App\Domain\Accounting\ChartOfAccounts\Models\AccountType;
use Illuminate\Database\Seeder;

class AccountTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'name' => 'Asset',
                'slug' => 'asset',
                'classification' => 'asset',
                'normal_balance' => 'debit',
                'description' => 'Economic resources owned by the business that produce future benefits.',
                'groups' => [
                    ['name' => 'Current Assets', 'slug' => 'current-assets', 'sort_order' => 10],
                    ['name' => 'Bank & Cash', 'slug' => 'bank-cash', 'sort_order' => 15],
                    ['name' => 'Non-Current Assets', 'slug' => 'non-current-assets', 'sort_order' => 20],
                    ['name' => 'Fixed Assets', 'slug' => 'fixed-assets', 'sort_order' => 30],
                ],
            ],
            [
                'name' => 'Liability',
                'slug' => 'liability',
                'classification' => 'liability',
                'normal_balance' => 'credit',
                'description' => 'Financial debts or obligations arising during business operations.',
                'groups' => [
                    ['name' => 'Current Liabilities', 'slug' => 'current-liabilities', 'sort_order' => 10],
                    ['name' => 'Duties & Taxes Payable', 'slug' => 'taxes-payable', 'sort_order' => 20],
                    ['name' => 'Non-Current Liabilities', 'slug' => 'non-current-liabilities', 'sort_order' => 30],
                ],
            ],
            [
                'name' => 'Equity',
                'slug' => 'equity',
                'classification' => 'equity',
                'normal_balance' => 'credit',
                'description' => 'Owners residual interest in the assets after deducting liabilities.',
                'groups' => [
                    ['name' => 'Capital & Reserves', 'slug' => 'capital-reserves', 'sort_order' => 10],
                ],
            ],
            [
                'name' => 'Revenue',
                'slug' => 'revenue',
                'classification' => 'revenue',
                'normal_balance' => 'credit',
                'description' => 'Inflows from sale of goods, rendering of services, and other earnings.',
                'groups' => [
                    ['name' => 'Operating Revenue', 'slug' => 'operating-revenue', 'sort_order' => 10],
                    ['name' => 'Other Revenue & Income', 'slug' => 'other-revenue', 'sort_order' => 20],
                ],
            ],
            [
                'name' => 'Expense',
                'slug' => 'expense',
                'classification' => 'expense',
                'normal_balance' => 'debit',
                'description' => 'Outflows or consumption of assets incurred to generate revenue.',
                'groups' => [
                    ['name' => 'Cost of Goods Sold', 'slug' => 'cost-of-goods-sold', 'sort_order' => 10],
                    ['name' => 'Operating & Administrative Expenses', 'slug' => 'operating-admin-expenses', 'sort_order' => 20],
                    ['name' => 'Financial & Bank Charges', 'slug' => 'financial-bank-charges', 'sort_order' => 30],
                    ['name' => 'Taxation Expense', 'slug' => 'taxation-expense', 'sort_order' => 40],
                ],
            ],
        ];

        foreach ($types as $typeData) {
            $groups = $typeData['groups'];
            unset($typeData['groups']);

            $type = AccountType::updateOrCreate(
                ['slug' => $typeData['slug']],
                $typeData
            );

            foreach ($groups as $groupData) {
                AccountGroup::updateOrCreate(
                    [
                        'account_type_id' => $type->id,
                        'slug' => $groupData['slug'],
                    ],
                    $groupData
                );
            }
        }
    }
}
