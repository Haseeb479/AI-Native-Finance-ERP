<?php

namespace App\Http\Controllers\Api\V1\Internal;

use App\Domain\Accounting\ChartOfAccounts\Models\Account;
use App\Domain\Accounting\Journal\Models\JournalLine;
use App\Domain\Accounting\Posting\Services\PostingEngine;
use App\Domain\AI\Models\AiDraft;
use App\Domain\Organization\Models\Organization;
use App\Domain\Reporting\Services\ReportingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InternalAiCapabilityController extends Controller
{
    public function __construct(
        private readonly PostingEngine $postingEngine,
        private readonly ReportingService $reportingService
    ) {}

    /**
     * Real Chart of Accounts lookup for AI tools.
     * GET /api/v1/internal/organizations/{orgId}/accounts/lookup
     */
    public function lookupAccount(Request $request, string $orgId): JsonResponse
    {
        $code = $request->query('code');
        $accountId = $request->query('id');

        $query = Account::withoutGlobalScopes()->where('organization_id', $orgId);

        if ($code) {
            $query->where('code', $code);
        } elseif ($accountId) {
            $query->where('id', $accountId);
        } else {
            return response()->json([
                'data' => null,
                'meta' => [],
                'errors' => [['code' => 'MISSING_PARAM', 'message' => 'Either code or id must be provided.']],
            ], 422);
        }

        $account = $query->first();

        if (! $account) {
            return response()->json([
                'data' => null,
                'meta' => [],
                'errors' => [['code' => 'ACCOUNT_NOT_FOUND', 'message' => "Account not found for organization {$orgId}."]],
            ], 404);
        }

        // Calculate real posted balance
        $totals = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
            ->where('jl.organization_id', $orgId)
            ->where('jl.account_id', $account->id)
            ->where('je.status', 'posted')
            ->selectRaw('COALESCE(SUM(jl.debit), 0) as total_debit, COALESCE(SUM(jl.credit), 0) as total_credit')
            ->first();

        $debit = (float) ($totals->total_debit ?? 0);
        $credit = (float) ($totals->total_credit ?? 0);
        $netBalance = $account->normal_balance === 'debit' ? ($debit - $credit) : ($credit - $debit);

        return response()->json([
            'data' => [
                'id' => $account->id,
                'account_code' => $account->code,
                'account_name' => $account->name,
                'type' => $account->type,
                'classification' => $account->classification,
                'normal_balance' => $account->normal_balance,
                'is_active' => (bool) $account->is_active,
                'is_control_account' => $account->isControlAccount(),
                'currency' => $account->currency ?? 'PKR',
                'current_balance' => number_format($netBalance, 2, '.', ''),
                'total_debit' => number_format($debit, 2, '.', ''),
                'total_credit' => number_format($credit, 2, '.', ''),
            ],
            'meta' => ['as_of' => now()->toISOString()],
            'errors' => [],
        ]);
    }

    /**
     * Real transaction search for AI tools.
     * GET /api/v1/internal/organizations/{orgId}/transactions/search
     */
    public function searchTransactions(Request $request, string $orgId): JsonResponse
    {
        $search = $request->query('query', '');
        $limit = min((int) $request->query('limit', 10), 50);

        $query = JournalLine::withoutGlobalScopes()
            ->join('journal_entries as je', 'journal_lines.journal_entry_id', '=', 'je.id')
            ->join('accounts as a', 'journal_lines.account_id', '=', 'a.id')
            ->where('journal_lines.organization_id', $orgId)
            ->where('je.status', 'posted');

        if (! empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('journal_lines.description', 'LIKE', "%{$search}%")
                  ->orWhere('je.description', 'LIKE', "%{$search}%")
                  ->orWhere('je.entry_number', 'LIKE', "%{$search}%")
                  ->orWhere('a.name', 'LIKE', "%{$search}%")
                  ->orWhere('a.code', 'LIKE', "%{$search}%");
            });
        }

        $results = $query->select([
            'journal_lines.id',
            'je.id as journal_entry_id',
            'je.entry_number',
            'je.entry_date',
            'journal_lines.description',
            'journal_lines.debit',
            'journal_lines.credit',
            'journal_lines.currency',
            'a.code as account_code',
            'a.name as account_name',
        ])
        ->orderBy('je.entry_date', 'desc')
        ->limit($limit)
        ->get();

        return response()->json([
            'data' => [
                'results' => $results,
                'total_count' => $results->count(),
            ],
            'meta' => ['timestamp' => now()->toISOString()],
            'errors' => [],
        ]);
    }

    /**
     * Persist an AI-generated draft in the real database (P1-14).
     * POST /api/v1/internal/organizations/{orgId}/ai/drafts
     */
    public function createDraft(Request $request, string $orgId): JsonResponse
    {
        $org = Organization::findOrFail($orgId);

        $validated = $request->validate([
            'draft_type' => ['required', 'string', 'in:journal_entry,invoice_draft,bill_draft,adjustment'],
            'title' => ['required', 'string', 'max:255'],
            'input_context' => ['nullable', 'array'],
            'proposed_payload' => ['required', 'array'],
            'evidence' => ['nullable', 'array'],
            'entity_id' => ['nullable', 'uuid'],
        ]);

        $payload = $validated['proposed_payload'];
        $validationResult = ['valid' => true, 'errors' => []];

        // Validate double-entry mathematical invariants if it's a journal
        if ($validated['draft_type'] === 'journal_entry') {
            $lines = $payload['lines'] ?? [];
            if (count($lines) < 2) {
                $validationResult = [
                    'valid' => false,
                    'errors' => ['Journal draft must have at least 2 lines for double-entry balance.'],
                ];
            } else {
                $totalDebit = 0.0;
                $totalCredit = 0.0;
                foreach ($lines as $line) {
                    $totalDebit += (float) ($line['debit'] ?? 0);
                    $totalCredit += (float) ($line['credit'] ?? 0);
                }

                if (abs($totalDebit - $totalCredit) >= 0.0001 || $totalDebit <= 0) {
                    $validationResult = [
                        'valid' => false,
                        'errors' => [sprintf('Double-entry violation: Total Debit (%.2f) != Total Credit (%.2f)', $totalDebit, $totalCredit)],
                    ];
                }
            }
        }

        $draft = AiDraft::create([
            'organization_id' => $org->id,
            'entity_id' => $validated['entity_id'] ?? null,
            'user_id' => $request->user()?->id,
            'draft_type' => $validated['draft_type'],
            'title' => $validated['title'],
            'input_context' => $validated['input_context'] ?? [],
            'proposed_payload' => $payload,
            'validation_result' => $validationResult,
            'evidence' => array_merge($validated['evidence'] ?? [], [
                'server_timestamp' => now()->toISOString(),
                'organization_id' => $org->id,
            ]),
            'status' => 'pending_review',
        ]);

        return response()->json([
            'data' => [
                'draft_id' => $draft->id,
                'status' => $draft->status,
                'is_valid' => $validationResult['valid'],
                'validation_errors' => $validationResult['errors'],
                'message' => 'AI draft persisted successfully. Must be reviewed and approved by an authorized accountant.',
            ],
            'meta' => ['timestamp' => now()->toISOString()],
            'errors' => [],
        ], 201);
    }

    /**
     * Approve and execute a persisted AI draft into the ledger.
     * POST /api/v1/internal/organizations/{orgId}/ai/drafts/{draftId}/approve
     */
    public function approveDraft(Request $request, string $orgId, string $draftId): JsonResponse
    {
        $org = Organization::findOrFail($orgId);
        $user = $request->user();

        $draft = AiDraft::where('organization_id', $org->id)->findOrFail($draftId);

        if (! $draft->isPending()) {
            return response()->json([
                'data' => null,
                'meta' => [],
                'errors' => [['code' => 'INVALID_STATUS', 'message' => "Draft cannot be approved from status '{$draft->status}'."]],
            ], 422);
        }

        if ($draft->draft_type === 'journal_entry') {
            $payload = $draft->proposed_payload;
            $journalDraft = $this->postingEngine->createDraft($org, [
                'entry_date' => $payload['entry_date'] ?? now()->toDateString(),
                'description' => "[AI Draft Approved] " . $draft->title,
                'lines' => $payload['lines'],
                'currency' => $payload['currency'] ?? $org->base_currency ?? 'PKR',
                'source_type' => 'ai_draft',
                'source_id' => $draft->id,
            ], $user);

            $draft->update([
                'status' => 'approved',
                'approved_by' => $user->id,
                'approved_at' => now(),
                'resulting_record_type' => get_class($journalDraft),
                'resulting_record_id' => $journalDraft->id,
            ]);

            return response()->json([
                'data' => [
                    'draft_id' => $draft->id,
                    'status' => 'approved',
                    'journal_entry_id' => $journalDraft->id,
                    'entry_number' => $journalDraft->entry_number,
                ],
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [],
            ]);
        }

        return response()->json([
            'data' => null,
            'meta' => [],
            'errors' => [['code' => 'UNSUPPORTED_DRAFT_TYPE', 'message' => "Unsupported draft type {$draft->draft_type}"]],
        ], 422);
    }
}
