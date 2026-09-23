<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| AI-Native Finance ERP REST API endpoints.
| All responses strictly adhere to the standard envelope format:
| { "data": ..., "meta": ..., "errors": [] }
|
*/

Route::prefix('v1')->group(function () {
    Route::get('/health', function (): JsonResponse {
        $dbStatus = 'ok';
        $dbError = null;

        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $dbStatus = 'error';
            $dbError = $e->getMessage();
        }

        $healthy = ($dbStatus === 'ok');

        return response()->json([
            'data' => [
                'status' => $healthy ? 'healthy' : 'degraded',
                'services' => [
                    'database' => [
                        'status' => $dbStatus,
                        'driver' => config('database.default'),
                        'error' => $dbError,
                    ],
                ],
                'version' => 'v1.0.0',
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'environment' => config('app.env'),
            ],
            'errors' => $dbError ? [$dbError] : [],
        ], $healthy ? 200 : 503);
    });

    // Authentication Routes
    Route::prefix('auth')->group(function () {
        Route::post('/register', [\App\Http\Controllers\Api\V1\AuthController::class, 'register']);
        Route::post('/login', [\App\Http\Controllers\Api\V1\AuthController::class, 'login']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', [\App\Http\Controllers\Api\V1\AuthController::class, 'logout']);
            Route::get('/me', [\App\Http\Controllers\Api\V1\AuthController::class, 'me']);
        });
    });

    // Multi-Tenant Protected Routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/organizations', [\App\Http\Controllers\Api\V1\OrganizationController::class, 'index']);
        Route::post('/organizations', [\App\Http\Controllers\Api\V1\OrganizationController::class, 'store']);
        Route::get('/organizations/{id}', [\App\Http\Controllers\Api\V1\OrganizationController::class, 'show']);

        // Member & RBAC Management
        Route::get('/organizations/{id}/members', [\App\Http\Controllers\Api\V1\OrganizationMemberController::class, 'index']);
        Route::post('/organizations/{id}/members', [\App\Http\Controllers\Api\V1\OrganizationMemberController::class, 'store']);
        Route::delete('/organizations/{id}/members/{userId}', [\App\Http\Controllers\Api\V1\OrganizationMemberController::class, 'destroy']);

        // Chart of Accounts (COA) Management
        Route::get('/account-types', [\App\Http\Controllers\Api\V1\AccountController::class, 'types']);
        Route::get('/organizations/{orgId}/accounts', [\App\Http\Controllers\Api\V1\AccountController::class, 'index']);
        Route::post('/organizations/{orgId}/accounts', [\App\Http\Controllers\Api\V1\AccountController::class, 'store']);
        Route::post('/organizations/{orgId}/accounts/seed-template', [\App\Http\Controllers\Api\V1\AccountController::class, 'seedTemplate']);
        Route::get('/organizations/{orgId}/accounts/{accountId}', [\App\Http\Controllers\Api\V1\AccountController::class, 'show']);
        Route::put('/organizations/{orgId}/accounts/{accountId}', [\App\Http\Controllers\Api\V1\AccountController::class, 'update']);
        Route::delete('/organizations/{orgId}/accounts/{accountId}', [\App\Http\Controllers\Api\V1\AccountController::class, 'destroy']);

        // Fiscal Years & Accounting Periods
        Route::get('/organizations/{orgId}/fiscal-years', [\App\Http\Controllers\Api\V1\PeriodController::class, 'indexFiscalYears']);
        Route::post('/organizations/{orgId}/fiscal-years', [\App\Http\Controllers\Api\V1\PeriodController::class, 'storeFiscalYear']);
        Route::get('/organizations/{orgId}/periods', [\App\Http\Controllers\Api\V1\PeriodController::class, 'indexPeriods']);
        Route::post('/organizations/{orgId}/periods/{periodId}/close', [\App\Http\Controllers\Api\V1\PeriodController::class, 'close']);
        Route::post('/organizations/{orgId}/periods/{periodId}/reopen', [\App\Http\Controllers\Api\V1\PeriodController::class, 'reopen']);
        Route::post('/organizations/{orgId}/periods/{periodId}/lock', [\App\Http\Controllers\Api\V1\PeriodController::class, 'lock']);

        // General Ledger Double-Entry Journals
        Route::get('/organizations/{orgId}/journals', [\App\Http\Controllers\Api\V1\JournalController::class, 'index']);
        Route::post('/organizations/{orgId}/journals', [\App\Http\Controllers\Api\V1\JournalController::class, 'store']);
        Route::get('/organizations/{orgId}/journals/{journalId}', [\App\Http\Controllers\Api\V1\JournalController::class, 'show']);
        Route::put('/organizations/{orgId}/journals/{journalId}', [\App\Http\Controllers\Api\V1\JournalController::class, 'update']);
        Route::post('/organizations/{orgId}/journals/{journalId}/post', [\App\Http\Controllers\Api\V1\JournalController::class, 'post']);
        Route::post('/organizations/{orgId}/journals/{journalId}/reverse', [\App\Http\Controllers\Api\V1\JournalController::class, 'reverse']);

        // Customers (Accounts Receivable)
        Route::get('/organizations/{orgId}/customers', [\App\Http\Controllers\Api\V1\CustomerController::class, 'index']);
        Route::post('/organizations/{orgId}/customers', [\App\Http\Controllers\Api\V1\CustomerController::class, 'store']);
        Route::get('/organizations/{orgId}/customers/{customerId}', [\App\Http\Controllers\Api\V1\CustomerController::class, 'show']);
        Route::put('/organizations/{orgId}/customers/{customerId}', [\App\Http\Controllers\Api\V1\CustomerController::class, 'update']);
        Route::delete('/organizations/{orgId}/customers/{customerId}', [\App\Http\Controllers\Api\V1\CustomerController::class, 'destroy']);

        // Sales Invoices & AR Receipts
        Route::get('/organizations/{orgId}/invoices', [\App\Http\Controllers\Api\V1\SalesInvoiceController::class, 'index']);
        Route::post('/organizations/{orgId}/invoices', [\App\Http\Controllers\Api\V1\SalesInvoiceController::class, 'store']);
        Route::get('/organizations/{orgId}/invoices/{invoiceId}', [\App\Http\Controllers\Api\V1\SalesInvoiceController::class, 'show']);
        Route::post('/organizations/{orgId}/invoices/{invoiceId}/submit', [\App\Http\Controllers\Api\V1\SalesInvoiceController::class, 'submit']);
        Route::post('/organizations/{orgId}/invoices/{invoiceId}/approve', [\App\Http\Controllers\Api\V1\SalesInvoiceController::class, 'approve']);
        Route::post('/organizations/{orgId}/invoices/{invoiceId}/reject', [\App\Http\Controllers\Api\V1\SalesInvoiceController::class, 'reject']);
        Route::post('/organizations/{orgId}/invoices/{invoiceId}/post', [\App\Http\Controllers\Api\V1\SalesInvoiceController::class, 'post']);
        Route::post('/organizations/{orgId}/invoices/{invoiceId}/payments', [\App\Http\Controllers\Api\V1\SalesInvoiceController::class, 'recordPayment']);

        // Vendors (Accounts Payable)
        Route::get('/organizations/{orgId}/vendors', [\App\Http\Controllers\Api\V1\VendorController::class, 'index']);
        Route::post('/organizations/{orgId}/vendors', [\App\Http\Controllers\Api\V1\VendorController::class, 'store']);
        Route::get('/organizations/{orgId}/vendors/{vendorId}', [\App\Http\Controllers\Api\V1\VendorController::class, 'show']);
        Route::put('/organizations/{orgId}/vendors/{vendorId}', [\App\Http\Controllers\Api\V1\VendorController::class, 'update']);
        Route::delete('/organizations/{orgId}/vendors/{vendorId}', [\App\Http\Controllers\Api\V1\VendorController::class, 'destroy']);

        // Purchase Bills & AP Disbursements
        Route::get('/organizations/{orgId}/bills', [\App\Http\Controllers\Api\V1\PurchaseBillController::class, 'index']);
        Route::post('/organizations/{orgId}/bills', [\App\Http\Controllers\Api\V1\PurchaseBillController::class, 'store']);
        Route::get('/organizations/{orgId}/bills/{billId}', [\App\Http\Controllers\Api\V1\PurchaseBillController::class, 'show']);
        Route::post('/organizations/{orgId}/bills/{billId}/submit', [\App\Http\Controllers\Api\V1\PurchaseBillController::class, 'submit']);
        Route::post('/organizations/{orgId}/bills/{billId}/approve', [\App\Http\Controllers\Api\V1\PurchaseBillController::class, 'approve']);
        Route::post('/organizations/{orgId}/bills/{billId}/reject', [\App\Http\Controllers\Api\V1\PurchaseBillController::class, 'reject']);
        Route::post('/organizations/{orgId}/bills/{billId}/post', [\App\Http\Controllers\Api\V1\PurchaseBillController::class, 'post']);
        Route::post('/organizations/{orgId}/bills/{billId}/payments', [\App\Http\Controllers\Api\V1\PurchaseBillController::class, 'recordPayment']);

        // Banking & Cash Management
        Route::get('/organizations/{orgId}/bank-accounts', [\App\Http\Controllers\Api\V1\BankController::class, 'index']);
        Route::post('/organizations/{orgId}/bank-accounts', [\App\Http\Controllers\Api\V1\BankController::class, 'store']);
        Route::get('/organizations/{orgId}/bank-accounts/{bankAccountId}', [\App\Http\Controllers\Api\V1\BankController::class, 'show']);
        Route::post('/organizations/{orgId}/bank-accounts/{bankAccountId}/import-statement', [\App\Http\Controllers\Api\V1\BankController::class, 'importStatement']);
        Route::get('/organizations/{orgId}/bank-accounts/{bankAccountId}/transactions', [\App\Http\Controllers\Api\V1\BankController::class, 'transactions']);
        Route::get('/organizations/{orgId}/bank-accounts/{bankAccountId}/suggestions', [\App\Http\Controllers\Api\V1\BankController::class, 'suggestions']);
        Route::post('/organizations/{orgId}/bank-transactions/{transactionId}/reconcile', [\App\Http\Controllers\Api\V1\BankController::class, 'reconcile']);
        Route::post('/organizations/{orgId}/bank-transactions/{transactionId}/unreconcile', [\App\Http\Controllers\Api\V1\BankController::class, 'unreconcile']);

        // Documents & OCR Pipeline
        Route::get('/organizations/{orgId}/documents', [\App\Http\Controllers\Api\V1\DocumentController::class, 'index']);
        Route::post('/organizations/{orgId}/documents', [\App\Http\Controllers\Api\V1\DocumentController::class, 'store']);
        Route::get('/organizations/{orgId}/documents/{documentId}', [\App\Http\Controllers\Api\V1\DocumentController::class, 'show']);
        Route::get('/organizations/{orgId}/documents/{documentId}/preview-url', [\App\Http\Controllers\Api\V1\DocumentController::class, 'previewUrl']);
        Route::post('/organizations/{orgId}/documents/{documentId}/approve', [\App\Http\Controllers\Api\V1\DocumentController::class, 'approve']);
        Route::post('/organizations/{orgId}/documents/{documentId}/reject', [\App\Http\Controllers\Api\V1\DocumentController::class, 'reject']);

        // Financial Reports
        Route::get('/organizations/{orgId}/reports/trial-balance', [\App\Http\Controllers\Api\V1\ReportController::class, 'trialBalance']);
        Route::get('/organizations/{orgId}/reports/profit-and-loss', [\App\Http\Controllers\Api\V1\ReportController::class, 'profitAndLoss']);
        Route::get('/organizations/{orgId}/reports/balance-sheet', [\App\Http\Controllers\Api\V1\ReportController::class, 'balanceSheet']);
        Route::get('/organizations/{orgId}/reports/general-ledger', [\App\Http\Controllers\Api\V1\ReportController::class, 'generalLedger']);
        Route::get('/organizations/{orgId}/reports/ar-aging', [\App\Http\Controllers\Api\V1\ReportController::class, 'arAging']);
        Route::get('/organizations/{orgId}/reports/ap-aging', [\App\Http\Controllers\Api\V1\ReportController::class, 'apAging']);

        // AI Foundation & Gateway
        Route::post('/organizations/{orgId}/ai/classify-transaction', [\App\Http\Controllers\Api\V1\AiGatewayController::class, 'classifyTransaction']);
        Route::get('/organizations/{orgId}/ai/usage-metrics', [\App\Http\Controllers\Api\V1\AiGatewayController::class, 'usageMetrics']);
        Route::get('/organizations/{orgId}/ai/logs', [\App\Http\Controllers\Api\V1\AiGatewayController::class, 'runLogs']);

        // AI Copilot Features (Q&A, Journal Drafting, Report Explanation)
        Route::post('/organizations/{orgId}/ai/copilot/qa', [\App\Http\Controllers\Api\V1\AiGatewayController::class, 'askCopilot']);
        Route::post('/organizations/{orgId}/ai/copilot/draft-journal', [\App\Http\Controllers\Api\V1\AiGatewayController::class, 'draftJournal']);
        Route::post('/organizations/{orgId}/ai/copilot/explain-report', [\App\Http\Controllers\Api\V1\AiGatewayController::class, 'explainReport']);

        // Step 21: Audit & Compliance Trails
        Route::get('/organizations/{orgId}/audit-logs', [\App\Http\Controllers\Api\V1\AuditController::class, 'index']);
        Route::get('/organizations/{orgId}/audit-logs/export', [\App\Http\Controllers\Api\V1\AuditController::class, 'export']);
        Route::get('/organizations/{orgId}/audit-logs/verify', [\App\Http\Controllers\Api\V1\AuditController::class, 'verify']);

        // Step 22: Pakistan Localization & FBR Digital Invoicing
        Route::post('/organizations/{orgId}/invoices/{invoiceId}/fbr-fiscalize', [\App\Http\Controllers\Api\V1\FbrInvoiceController::class, 'fiscalize']);
        Route::get('/organizations/{orgId}/invoices/{invoiceId}/fbr-qr', [\App\Http\Controllers\Api\V1\FbrInvoiceController::class, 'getQrCode']);
        Route::post('/organizations/{orgId}/taxation/pakistan/validate-tax-id', [\App\Http\Controllers\Api\V1\FbrInvoiceController::class, 'validateTaxId']);
        Route::get('/organizations/{orgId}/taxation/pakistan/summary', [\App\Http\Controllers\Api\V1\FbrInvoiceController::class, 'summary']);

        // Step 23: Close Management & Flux Analysis
        Route::get('/organizations/{orgId}/close-cycles/{periodId}', [\App\Http\Controllers\Api\V1\CloseController::class, 'showCycle']);
        Route::post('/organizations/{orgId}/close-cycles/{periodId}/tasks/{taskId}/toggle', [\App\Http\Controllers\Api\V1\CloseController::class, 'toggleTask']);
        Route::post('/organizations/{orgId}/close-cycles/{periodId}/depreciation', [\App\Http\Controllers\Api\V1\CloseController::class, 'runDepreciation']);
        Route::get('/organizations/{orgId}/close-cycles/{periodId}/flux-analysis', [\App\Http\Controllers\Api\V1\CloseController::class, 'fluxAnalysis']);
        Route::get('/organizations/{orgId}/close-cycles/{periodId}/readiness', [\App\Http\Controllers\Api\V1\CloseController::class, 'readiness']);
        Route::get('/organizations/{orgId}/fixed-assets', [\App\Http\Controllers\Api\V1\CloseController::class, 'indexFixedAssets']);
        Route::post('/organizations/{orgId}/fixed-assets', [\App\Http\Controllers\Api\V1\CloseController::class, 'storeFixedAsset']);

        // Step 24: Multi-Entity, Multi-Currency & Intercompany Consolidation
        Route::get('/organizations/{orgId}/entities', [\App\Http\Controllers\Api\V1\ConsolidationController::class, 'indexEntities']);
        Route::post('/organizations/{orgId}/entities', [\App\Http\Controllers\Api\V1\ConsolidationController::class, 'storeEntity']);
        Route::get('/organizations/{orgId}/exchange-rates', [\App\Http\Controllers\Api\V1\ConsolidationController::class, 'indexRates']);
        Route::post('/organizations/{orgId}/exchange-rates', [\App\Http\Controllers\Api\V1\ConsolidationController::class, 'storeRate']);
        Route::post('/organizations/{orgId}/currency-revaluation', [\App\Http\Controllers\Api\V1\ConsolidationController::class, 'runRevaluation']);
        Route::get('/organizations/{orgId}/intercompany-transactions', [\App\Http\Controllers\Api\V1\ConsolidationController::class, 'indexIntercompany']);
        Route::post('/organizations/{orgId}/intercompany-transactions', [\App\Http\Controllers\Api\V1\ConsolidationController::class, 'storeIntercompany']);
        Route::post('/organizations/{orgId}/intercompany-transactions/{id}/post', [\App\Http\Controllers\Api\V1\ConsolidationController::class, 'postIntercompany']);
        Route::post('/organizations/{orgId}/consolidation/eliminate', [\App\Http\Controllers\Api\V1\ConsolidationController::class, 'eliminate']);
        Route::get('/organizations/{orgId}/consolidation/reports/{reportType}', [\App\Http\Controllers\Api\V1\ConsolidationController::class, 'report']);

        // Step 25: Procurement & 3-Way Matching
        Route::get('/organizations/{orgId}/procurement/requisitions', [\App\Http\Controllers\Api\V1\ProcurementController::class, 'indexRequisitions']);
        Route::post('/organizations/{orgId}/procurement/requisitions', [\App\Http\Controllers\Api\V1\ProcurementController::class, 'storeRequisition']);
        Route::post('/organizations/{orgId}/procurement/requisitions/{id}/approve', [\App\Http\Controllers\Api\V1\ProcurementController::class, 'approveRequisition']);
        Route::post('/organizations/{orgId}/procurement/requisitions/{id}/convert', [\App\Http\Controllers\Api\V1\ProcurementController::class, 'convertRequisitionToPo']);
        Route::get('/organizations/{orgId}/procurement/orders', [\App\Http\Controllers\Api\V1\ProcurementController::class, 'indexOrders']);
        Route::post('/organizations/{orgId}/procurement/orders', [\App\Http\Controllers\Api\V1\ProcurementController::class, 'storeOrder']);
        Route::post('/organizations/{orgId}/procurement/orders/{id}/issue', [\App\Http\Controllers\Api\V1\ProcurementController::class, 'issueOrder']);
        Route::post('/organizations/{orgId}/procurement/orders/{id}/receive-goods', [\App\Http\Controllers\Api\V1\ProcurementController::class, 'receiveGoods']);
        Route::post('/organizations/{orgId}/procurement/orders/{id}/generate-bill', [\App\Http\Controllers\Api\V1\ProcurementController::class, 'generateBill']);
        Route::post('/organizations/{orgId}/procurement/3way-match', [\App\Http\Controllers\Api\V1\ProcurementController::class, 'matchThreeWay']);
        Route::post('/organizations/{orgId}/procurement/3way-match/{id}/waive', [\App\Http\Controllers\Api\V1\ProcurementController::class, 'waiveMatch']);
        Route::get('/organizations/{orgId}/procurement/3way-matches', [\App\Http\Controllers\Api\V1\ProcurementController::class, 'indexMatches']);

        // Step 26: Inventory Valuation & Automated COGS Engine
        Route::get('/organizations/{orgId}/inventory/products', [\App\Http\Controllers\Api\V1\InventoryController::class, 'indexProducts']);
        Route::post('/organizations/{orgId}/inventory/products', [\App\Http\Controllers\Api\V1\InventoryController::class, 'storeProduct']);
        Route::get('/organizations/{orgId}/inventory/warehouses', [\App\Http\Controllers\Api\V1\InventoryController::class, 'indexWarehouses']);
        Route::post('/organizations/{orgId}/inventory/warehouses', [\App\Http\Controllers\Api\V1\InventoryController::class, 'storeWarehouse']);
        Route::post('/organizations/{orgId}/inventory/stock/receive', [\App\Http\Controllers\Api\V1\InventoryController::class, 'receiveStock']);
        Route::post('/organizations/{orgId}/inventory/stock/adjust', [\App\Http\Controllers\Api\V1\InventoryController::class, 'adjustStock']);
        Route::post('/organizations/{orgId}/inventory/stock/transfer', [\App\Http\Controllers\Api\V1\InventoryController::class, 'transferStock']);
        Route::get('/organizations/{orgId}/inventory/valuation-report', [\App\Http\Controllers\Api\V1\InventoryController::class, 'valuationReport']);
        Route::post('/organizations/{orgId}/inventory/cogs/process-invoice', [\App\Http\Controllers\Api\V1\InventoryController::class, 'processInvoiceCogs']);
    });
});
