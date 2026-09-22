<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Journal\Models\JournalEntry;
use App\Domain\Banking\Models\BankAccount;
use App\Domain\Banking\Models\BankTransaction;
use App\Domain\Banking\Services\ReconciliationService;
use App\Domain\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Banking\CreateBankAccountRequest;
use App\Http\Requests\Banking\ImportBankStatementRequest;
use App\Http\Requests\Banking\ReconcileTransactionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankController extends Controller
{
    public function __construct(
        protected ReconciliationService $reconciliationService
    ) {}

    private function getAuthorizedOrganization(Request $request, string $orgId): ?Organization
    {
        return $request->user()?->organizations()
            ->where('organizations.id', $orgId)
            ->first();
    }

    private function unauthorizedResponse(): JsonResponse
    {
        return response()->json([
            'data' => null,
            'meta' => ['timestamp' => now()->toISOString()],
            'errors' => [
                [
                    'code' => 'ORGANIZATION_NOT_FOUND',
                    'message' => 'Organization not found or access denied.',
                ],
            ],
        ], 404);
    }

    /**
     * List bank accounts for organization.
     */
    public function index(Request $request, string $orgId): JsonResponse
    {
        $org = $this->getAuthorizedOrganization($request, $orgId);
        if (!$org) {
            return $this->unauthorizedResponse();
        }

        $accounts = BankAccount::where('organization_id', $org->id)
            ->with(['chartAccount:id,code,name'])
            ->withCount([
                'transactions as unreconciled_count' => fn($q) => $q->where('reconciliation_status', 'unreconciled')
            ])
            ->get();

        return response()->json([
            'data' => $accounts,
            'meta' => [
                'total' => $accounts->count(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Create a new bank account.
     */
    public function store(CreateBankAccountRequest $request, string $orgId): JsonResponse
    {
        $org = $this->getAuthorizedOrganization($request, $orgId);
        if (!$org) {
            return $this->unauthorizedResponse();
        }

        $account = BankAccount::create(array_merge($request->validated(), [
            'organization_id' => $org->id,
            'current_balance' => $request->input('opening_balance', 0.0),
        ]));

        return response()->json([
            'data' => $account->load('chartAccount'),
            'meta' => ['message' => 'Bank account created successfully.'],
            'errors' => [],
        ], 201);
    }

    /**
     * Get single bank account details with recent statements.
     */
    public function show(Request $request, string $orgId, string $id): JsonResponse
    {
        $org = $this->getAuthorizedOrganization($request, $orgId);
        if (!$org) {
            return $this->unauthorizedResponse();
        }

        $account = BankAccount::where('organization_id', $org->id)
            ->with(['chartAccount', 'statements' => fn($q) => $q->latest()->limit(5)])
            ->findOrFail($id);

        return response()->json([
            'data' => $account,
            'meta' => [],
            'errors' => [],
        ]);
    }

    /**
     * Import a CSV bank statement.
     */
    public function importStatement(ImportBankStatementRequest $request, string $orgId, string $id): JsonResponse
    {
        $org = $this->getAuthorizedOrganization($request, $orgId);
        if (!$org) {
            return $this->unauthorizedResponse();
        }

        $bankAccount = BankAccount::where('organization_id', $org->id)->findOrFail($id);

        $csvContent = '';
        $filename = $request->input('filename', 'statement.csv');

        if ($request->hasFile('statement_file')) {
            $file = $request->file('statement_file');
            $csvContent = file_get_contents($file->getRealPath());
            $filename = $file->getClientOriginalName();
        } else {
            $csvContent = $request->input('csv_content', '');
        }

        try {
            $statement = $this->reconciliationService->importStatement(
                $bankAccount,
                $csvContent,
                $filename,
                $request->user()
            );

            return response()->json([
                'data' => $statement,
                'meta' => [
                    'message' => 'Bank statement imported successfully.',
                    'transactions_imported' => $statement->transactions->count(),
                ],
                'errors' => [],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'data' => null,
                'meta' => [],
                'errors' => [
                    [
                        'code' => 'STATEMENT_IMPORT_FAILED',
                        'message' => $e->getMessage(),
                    ],
                ],
            ], 422);
        }
    }

    /**
     * List transactions for a bank account with reconciliation status filter.
     */
    public function transactions(Request $request, string $orgId, string $id): JsonResponse
    {
        $org = $this->getAuthorizedOrganization($request, $orgId);
        if (!$org) {
            return $this->unauthorizedResponse();
        }

        $bankAccount = BankAccount::where('organization_id', $org->id)->findOrFail($id);

        $query = BankTransaction::where('organization_id', $org->id)
            ->where('bank_account_id', $bankAccount->id)
            ->with(['matchedJournalEntry:id,entry_number,description,total_debit,entry_date']);

        if ($request->filled('status')) {
            $query->where('reconciliation_status', $request->query('status'));
        }

        $transactions = $query->orderBy('transaction_date', 'desc')->get();

        return response()->json([
            'data' => $transactions,
            'meta' => ['total' => $transactions->count()],
            'errors' => [],
        ]);
    }

    /**
     * Get suggested reconciliation matches for unreconciled transactions.
     */
    public function suggestions(Request $request, string $orgId, string $id): JsonResponse
    {
        $org = $this->getAuthorizedOrganization($request, $orgId);
        if (!$org) {
            return $this->unauthorizedResponse();
        }

        $bankAccount = BankAccount::where('organization_id', $org->id)->findOrFail($id);

        $suggestions = $this->reconciliationService->suggestMatches($bankAccount);

        return response()->json([
            'data' => $suggestions,
            'meta' => ['unreconciled_count' => count($suggestions)],
            'errors' => [],
        ]);
    }

    /**
     * Match bank transaction with a GL journal entry.
     */
    public function reconcile(ReconcileTransactionRequest $request, string $orgId, string $transactionId): JsonResponse
    {
        $org = $this->getAuthorizedOrganization($request, $orgId);
        if (!$org) {
            return $this->unauthorizedResponse();
        }

        $transaction = BankTransaction::where('organization_id', $org->id)->findOrFail($transactionId);
        $journalEntry = JournalEntry::where('organization_id', $org->id)->findOrFail($request->input('journal_entry_id'));

        try {
            $matched = $this->reconciliationService->matchTransaction($transaction, $journalEntry, $request->user());

            return response()->json([
                'data' => $matched,
                'meta' => ['message' => 'Transaction reconciled successfully.'],
                'errors' => [],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'data' => null,
                'meta' => [],
                'errors' => [
                    [
                        'code' => 'RECONCILIATION_FAILED',
                        'message' => $e->getMessage(),
                    ],
                ],
            ], 422);
        }
    }

    /**
     * Unmatch and revert transaction to unreconciled.
     */
    public function unreconcile(Request $request, string $orgId, string $transactionId): JsonResponse
    {
        $org = $this->getAuthorizedOrganization($request, $orgId);
        if (!$org) {
            return $this->unauthorizedResponse();
        }

        $transaction = BankTransaction::where('organization_id', $org->id)->findOrFail($transactionId);

        $reverted = $this->reconciliationService->unmatchTransaction($transaction, $request->user());

        return response()->json([
            'data' => $reverted,
            'meta' => ['message' => 'Transaction unmatched successfully.'],
            'errors' => [],
        ]);
    }
}
