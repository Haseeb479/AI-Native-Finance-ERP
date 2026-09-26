<?php

namespace App\Domain\Accounting\ChartOfAccounts\Templates;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\ChartOfAccounts\Models\AccountGroup;
use App\Domain\Accounting\ChartOfAccounts\Models\AccountType;
use App\Domain\Organization\Models\Organization;
use Illuminate\Support\Facades\DB;

class PakistanSmeChartTemplate
{
    /**
     * Seed standard Pakistan SME Chart of Accounts for the given organization.
     */
    public static function seedForOrganization(Organization $organization): int
    {
        if (AccountType::count() === 0) {
            (new \Database\Seeders\AccountTypeSeeder())->run();
        }

        $types = AccountType::all()->keyBy('slug');
        $groups = AccountGroup::all()->keyBy('slug');

        $definitions = [
            // ==================== ASSETS ====================
            [
                'code' => '1010',
                'name' => 'Cash in Hand',
                'description' => 'Physical petty cash and drawer cash balances',
                'type_slug' => 'asset',
                'group_slug' => 'bank-cash',
                'classification' => 'asset',
                'normal_balance' => 'debit',
                'is_reconcilable' => true,
            ],
            [
                'code' => '1020',
                'name' => 'Meezan Bank Operations',
                'description' => 'Primary Islamic business operating account',
                'type_slug' => 'asset',
                'group_slug' => 'bank-cash',
                'classification' => 'asset',
                'normal_balance' => 'debit',
                'is_reconcilable' => true,
            ],
            [
                'code' => '1021',
                'name' => 'Habib Bank Limited (HBL)',
                'description' => 'Secondary commercial banking account',
                'type_slug' => 'asset',
                'group_slug' => 'bank-cash',
                'classification' => 'asset',
                'normal_balance' => 'debit',
                'is_reconcilable' => true,
            ],
            [
                'code' => '1030',
                'name' => 'Trade Debtors / Accounts Receivable',
                'description' => 'Money owed by customers for goods/services billed',
                'type_slug' => 'asset',
                'group_slug' => 'current-assets',
                'classification' => 'asset',
                'normal_balance' => 'debit',
                'is_reconcilable' => true,
                'is_system' => true,
                'is_control_account' => true,
                'control_type' => 'ar_control',
            ],
            [
                'code' => '1040',
                'name' => 'Withholding Tax (WHT) Receivable',
                'description' => 'Income tax withheld at source by clients under Income Tax Ordinance 2001',
                'type_slug' => 'asset',
                'group_slug' => 'current-assets',
                'classification' => 'asset',
                'normal_balance' => 'debit',
            ],
            [
                'code' => '1050',
                'name' => 'Sales Tax Input (FBR/PRA/SRB)',
                'description' => 'Input sales tax paid on raw materials and business purchases',
                'type_slug' => 'asset',
                'group_slug' => 'current-assets',
                'classification' => 'asset',
                'normal_balance' => 'debit',
            ],
            [
                'code' => '1060',
                'name' => 'Prepayments and Advances',
                'description' => 'Advance payments to suppliers, rent advances, security deposits',
                'type_slug' => 'asset',
                'group_slug' => 'current-assets',
                'classification' => 'asset',
                'normal_balance' => 'debit',
            ],
            [
                'code' => '1070',
                'name' => 'Merchandise Inventory',
                'description' => 'Finished products, materials and trading stock on hand',
                'type_slug' => 'asset',
                'group_slug' => 'current-assets',
                'classification' => 'asset',
                'normal_balance' => 'debit',
            ],
            [
                'code' => '1510',
                'name' => 'Computer and IT Equipment',
                'description' => 'Hardware, servers, laptops and tech equipment',
                'type_slug' => 'asset',
                'group_slug' => 'fixed-assets',
                'classification' => 'asset',
                'normal_balance' => 'debit',
            ],
            [
                'code' => '1520',
                'name' => 'Furniture & Fixtures',
                'description' => 'Office workstations, chairs, desks and interior fixtures',
                'type_slug' => 'asset',
                'group_slug' => 'fixed-assets',
                'classification' => 'asset',
                'normal_balance' => 'debit',
            ],
            [
                'code' => '1590',
                'name' => 'Accumulated Depreciation',
                'description' => 'Contra asset tracking historical depreciation of fixed assets',
                'type_slug' => 'asset',
                'group_slug' => 'fixed-assets',
                'classification' => 'asset',
                'normal_balance' => 'credit', // Contra asset normal credit
            ],

            // ==================== LIABILITIES ====================
            [
                'code' => '2010',
                'name' => 'Trade Creditors / Accounts Payable',
                'description' => 'Amounts owed to vendors and service providers',
                'type_slug' => 'liability',
                'group_slug' => 'current-liabilities',
                'classification' => 'liability',
                'normal_balance' => 'credit',
                'is_reconcilable' => true,
                'is_system' => true,
                'is_control_account' => true,
                'control_type' => 'ap_control',
            ],
            [
                'code' => '2020',
                'name' => 'Sales Tax Payable (Output Tax)',
                'description' => 'Sales tax collected on supplies for monthly FBR / PRA / SRB filing',
                'type_slug' => 'liability',
                'group_slug' => 'taxes-payable',
                'classification' => 'liability',
                'normal_balance' => 'credit',
            ],
            [
                'code' => '2030',
                'name' => 'WHT Payable on Vendor Payments',
                'description' => 'Income tax withheld from supplier invoices awaiting deposit to State Bank',
                'type_slug' => 'liability',
                'group_slug' => 'taxes-payable',
                'classification' => 'liability',
                'normal_balance' => 'credit',
            ],
            [
                'code' => '2040',
                'name' => 'WHT Payable on Salaries (Sec 149)',
                'description' => 'Payroll withholding tax to be deposited with CPR to FBR',
                'type_slug' => 'liability',
                'group_slug' => 'taxes-payable',
                'classification' => 'liability',
                'normal_balance' => 'credit',
            ],
            [
                'code' => '2050',
                'name' => 'Accrued Salaries & Wages',
                'description' => 'Salaries earned by staff not yet disbursed',
                'type_slug' => 'liability',
                'group_slug' => 'current-liabilities',
                'classification' => 'liability',
                'normal_balance' => 'credit',
            ],
            [
                'code' => '2060',
                'name' => 'Accrued Expenses & Utilities',
                'description' => 'Incurred electricity, rent, or other expenses unpaid at month end',
                'type_slug' => 'liability',
                'group_slug' => 'current-liabilities',
                'classification' => 'liability',
                'normal_balance' => 'credit',
            ],
            [
                'code' => '2510',
                'name' => 'Bank Financing / Islamic Murabaha',
                'description' => 'Long term financing or capital lease commitments',
                'type_slug' => 'liability',
                'group_slug' => 'non-current-liabilities',
                'classification' => 'liability',
                'normal_balance' => 'credit',
            ],

            // ==================== EQUITY ====================
            [
                'code' => '3010',
                'name' => "Share Capital / Owner's Equity",
                'description' => 'Initial and subsequent capital introduced by partners/owners',
                'type_slug' => 'equity',
                'group_slug' => 'capital-reserves',
                'classification' => 'equity',
                'normal_balance' => 'credit',
            ],
            [
                'code' => '3020',
                'name' => "Owner's Drawings",
                'description' => 'Contra equity account for capital withdrawals by owner',
                'type_slug' => 'equity',
                'group_slug' => 'capital-reserves',
                'classification' => 'equity',
                'normal_balance' => 'debit',
            ],
            [
                'code' => '3030',
                'name' => 'Retained Earnings',
                'description' => 'Accumulated net profit/loss carried forward from prior periods',
                'type_slug' => 'equity',
                'group_slug' => 'capital-reserves',
                'classification' => 'equity',
                'normal_balance' => 'credit',
                'is_system' => true,
            ],

            // ==================== REVENUE ====================
            [
                'code' => '4010',
                'name' => 'Sales Revenue - Local',
                'description' => 'Domestic product and goods invoicing revenue',
                'type_slug' => 'revenue',
                'group_slug' => 'operating-revenue',
                'classification' => 'revenue',
                'normal_balance' => 'credit',
            ],
            [
                'code' => '4020',
                'name' => 'Service & Consulting Revenue',
                'description' => 'Professional, technology, or consulting fees billed',
                'type_slug' => 'revenue',
                'group_slug' => 'operating-revenue',
                'classification' => 'revenue',
                'normal_balance' => 'credit',
            ],
            [
                'code' => '4030',
                'name' => 'Export Revenue (PSEB / IT / Goods)',
                'description' => 'Foreign remittances and export proceeds under PRCs',
                'type_slug' => 'revenue',
                'group_slug' => 'operating-revenue',
                'classification' => 'revenue',
                'normal_balance' => 'credit',
            ],
            [
                'code' => '4040',
                'name' => 'Sales Discounts & Rebates',
                'description' => 'Contra revenue for price reductions given to customers',
                'type_slug' => 'revenue',
                'group_slug' => 'operating-revenue',
                'classification' => 'revenue',
                'normal_balance' => 'debit',
            ],
            [
                'code' => '4090',
                'name' => 'Other Income / Bank Profit',
                'description' => 'Bank profit sharing, scrap sales, miscellaneous income',
                'type_slug' => 'revenue',
                'group_slug' => 'other-revenue',
                'classification' => 'revenue',
                'normal_balance' => 'credit',
            ],

            // ==================== EXPENSES ====================
            [
                'code' => '5010',
                'name' => 'Cost of Goods Sold - Purchases',
                'description' => 'Direct cost of inventory, materials, and items purchased for resale',
                'type_slug' => 'expense',
                'group_slug' => 'cost-of-goods-sold',
                'classification' => 'expense',
                'normal_balance' => 'debit',
            ],
            [
                'code' => '5020',
                'name' => 'Direct Labor & Freight Inward',
                'description' => 'Factory or delivery charges directly tied to inventory delivery',
                'type_slug' => 'expense',
                'group_slug' => 'cost-of-goods-sold',
                'classification' => 'expense',
                'normal_balance' => 'debit',
            ],
            [
                'code' => '6010',
                'name' => 'Salaries & Allowances',
                'description' => 'Monthly staff remuneration, medical and transport allowances',
                'type_slug' => 'expense',
                'group_slug' => 'operating-admin-expenses',
                'classification' => 'expense',
                'normal_balance' => 'debit',
            ],
            [
                'code' => '6020',
                'name' => 'Office Rent Expense',
                'description' => 'Commercial premises monthly rental charges',
                'type_slug' => 'expense',
                'group_slug' => 'operating-admin-expenses',
                'classification' => 'expense',
                'normal_balance' => 'debit',
            ],
            [
                'code' => '6030',
                'name' => 'Electricity & Power (Utilities)',
                'description' => 'Power utility bills (K-Electric, LESCO, IESCO, etc.)',
                'type_slug' => 'expense',
                'group_slug' => 'operating-admin-expenses',
                'classification' => 'expense',
                'normal_balance' => 'debit',
            ],
            [
                'code' => '6040',
                'name' => 'Internet & Telecommunication',
                'description' => 'Broadband, cellular services, VoIP costs',
                'type_slug' => 'expense',
                'group_slug' => 'operating-admin-expenses',
                'classification' => 'expense',
                'normal_balance' => 'debit',
            ],
            [
                'code' => '6050',
                'name' => 'Software & Cloud Subscriptions',
                'description' => 'SaaS licenses, AWS/GCP hosting, productivity software',
                'type_slug' => 'expense',
                'group_slug' => 'operating-admin-expenses',
                'classification' => 'expense',
                'normal_balance' => 'debit',
            ],
            [
                'code' => '6060',
                'name' => 'Professional, Tax & Legal Fees',
                'description' => 'Chartered accountant, corporate lawyer, and tax filing advisor retainer',
                'type_slug' => 'expense',
                'group_slug' => 'operating-admin-expenses',
                'classification' => 'expense',
                'normal_balance' => 'debit',
            ],
            [
                'code' => '6070',
                'name' => 'Depreciation Expense',
                'description' => 'Monthly charge for wear and tear of physical capital assets',
                'type_slug' => 'expense',
                'group_slug' => 'operating-admin-expenses',
                'classification' => 'expense',
                'normal_balance' => 'debit',
            ],
            [
                'code' => '6080',
                'name' => 'Bank Charges & Transaction Duties',
                'description' => 'Cheque clearing fees, FED on banking, annual credit card fees',
                'type_slug' => 'expense',
                'group_slug' => 'financial-bank-charges',
                'classification' => 'expense',
                'normal_balance' => 'debit',
            ],
            [
                'code' => '6090',
                'name' => 'Income Tax Expense (Corporate)',
                'description' => 'Provision for annual corporate income tax or minimum turnover tax',
                'type_slug' => 'expense',
                'group_slug' => 'taxation-expense',
                'classification' => 'expense',
                'normal_balance' => 'debit',
            ],
        ];

        $createdCount = 0;

        DB::transaction(function () use ($organization, $definitions, $types, $groups, &$createdCount) {
            foreach ($definitions as $def) {
                $type = $types->get($def['type_slug']);
                $group = $groups->get($def['group_slug']);

                Account::withoutGlobalScopes()->updateOrCreate(
                    [
                        'organization_id' => $organization->id,
                        'code' => $def['code'],
                    ],
                    [
                        'account_type_id' => $type?->id,
                        'account_group_id' => $group?->id,
                        'name' => $def['name'],
                        'description' => $def['description'] ?? null,
                        'classification' => $def['classification'],
                        'normal_balance' => $def['normal_balance'],
                        'currency' => $organization->base_currency ?? 'PKR',
                        'is_active' => true,
                        'is_reconcilable' => $def['is_reconcilable'] ?? false,
                        'is_system' => $def['is_system'] ?? false,
                        'is_control_account' => $def['is_control_account'] ?? false,
                        'control_type' => $def['control_type'] ?? null,
                    ]
                );

                $createdCount++;
            }
        });

        return $createdCount;
    }
}
