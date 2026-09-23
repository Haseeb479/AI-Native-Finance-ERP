<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\Journal\Models\JournalEntry;
use App\Domain\Accounting\Posting\Services\PostingEngine;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Inventory\Models\Product;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Organization\Models\Organization;
use App\Domain\Sales\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CogsEngine
{
    public function __construct(
        protected InventoryService $inventoryService,
        protected PostingEngine $postingEngine
    ) {}

    /**
     * Process Cost of Goods Sold for a sales invoice, dispatch stock, and post balanced GL entry.
     *
     * Invariant:
     * DEBIT: Cost of Goods Sold (5010)
     * CREDIT: Merchandise Inventory (1070)
     * Sum(Debit) == Sum(Credit)
     */
    public function processInvoiceCogs(
        SalesInvoice $invoice,
        Warehouse $warehouse,
        User $user
    ): array {
        $organization = Organization::findOrFail($invoice->organization_id);

        return DB::transaction(function () use ($organization, $invoice, $warehouse, $user) {
            $totalCogs = 0.0;
            $dispatches = [];
            $productCogsMap = [];

            $invoice->load('lines.product');

            foreach ($invoice->lines as $line) {
                $product = $line->product;

                // If product is not linked on line, attempt to find product by description/sku
                if (! $product) {
                    $product = Product::withoutGlobalScopes()
                        ->where('organization_id', $organization->id)
                        ->where(function ($q) use ($line) {
                            $q->where('sku', $line->description)
                              ->orWhere('name', $line->description);
                        })
                        ->first();
                }

                if (! $product) {
                    continue; // Service or non-inventory line item
                }

                $qty = (float) $line->quantity;
                if ($qty <= 0) {
                    continue;
                }

                $dispatch = $this->inventoryService->recordStockDispatch(
                    $product,
                    $warehouse,
                    $qty,
                    'sales_invoice',
                    $invoice->id,
                    $user,
                    "COGS Dispatch for Invoice {$invoice->invoice_number} Line #{$line->line_number}"
                );

                $cost = (float) $dispatch['total_cost'];
                $totalCogs += $cost;

                $dispatches[] = [
                    'line_id' => $line->id,
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'quantity' => $qty,
                    'unit_cost' => $dispatch['unit_cost'],
                    'total_cost' => $cost,
                    'cogs_account_id' => $product->cogs_account_id,
                    'inventory_account_id' => $product->inventory_account_id,
                ];
            }

            if ($totalCogs <= 0.0) {
                return [
                    'total_cogs' => 0.0000,
                    'journal_entry' => null,
                    'dispatches' => [],
                ];
            }

            // Resolve General Ledger Accounts
            // 1. COGS Account (5010)
            $cogsAccount = Account::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('code', '5010')
                ->first();

            if (! $cogsAccount) {
                $cogsAccount = Account::withoutGlobalScopes()
                    ->where('organization_id', $organization->id)
                    ->where('classification', 'expense')
                    ->where('name', 'like', '%Cost of Goods Sold%')
                    ->first();
            }

            if (! $cogsAccount) {
                $cogsAccount = Account::withoutGlobalScopes()
                    ->where('organization_id', $organization->id)
                    ->where('classification', 'expense')
                    ->firstOrFail();
            }

            // 2. Merchandise Inventory Asset Account (1070)
            $invAccount = Account::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('code', '1070')
                ->first();

            if (! $invAccount) {
                $invAccount = Account::withoutGlobalScopes()
                    ->where('organization_id', $organization->id)
                    ->where('classification', 'asset')
                    ->where('name', 'like', '%Inventory%')
                    ->first();
            }

            if (! $invAccount) {
                $invAccount = Account::withoutGlobalScopes()
                    ->where('organization_id', $organization->id)
                    ->where('classification', 'asset')
                    ->firstOrFail();
            }

            // Build strictly balanced double-entry lines
            $journalLines = [
                [
                    'account_id' => $cogsAccount->id,
                    'description' => "COGS for Invoice {$invoice->invoice_number} ({$invoice->customer->name})",
                    'debit' => round($totalCogs, 4),
                    'credit' => 0.0000,
                ],
                [
                    'account_id' => $invAccount->id,
                    'description' => "Inventory reduction for Invoice {$invoice->invoice_number}",
                    'debit' => 0.0000,
                    'credit' => round($totalCogs, 4),
                ],
            ];

            // Post balanced journal entry via PostingEngine
            $draftJournal = $this->postingEngine->createDraft($organization, [
                'entry_date' => $invoice->issue_date->toDateString(),
                'source_type' => 'cogs',
                'source_id' => $invoice->id,
                'description' => "Automated COGS Entry for Invoice {$invoice->invoice_number}",
                'currency' => $invoice->currency ?? 'PKR',
                'lines' => $journalLines,
            ], $user);

            $postedJournal = $this->postingEngine->postEntry($draftJournal, $user);

            if (class_exists(AuditService::class)) {
                app(AuditService::class)->log(
                    $organization->id,
                    $user,
                    'inventory:cogs_posted',
                    $postedJournal,
                    [],
                    [
                        'invoice_id' => $invoice->id,
                        'total_cogs' => $totalCogs,
                        'warehouse_id' => $warehouse->id,
                        'journal_number' => $postedJournal->entry_number,
                    ]
                );
            }

            return [
                'total_cogs' => round($totalCogs, 4),
                'journal_entry' => $postedJournal,
                'dispatches' => $dispatches,
            ];
        });
    }
}
