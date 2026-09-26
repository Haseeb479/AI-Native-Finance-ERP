<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Journal\Models\JournalEntry;
use App\Domain\Accounting\Posting\Exceptions\ClosedPeriodException;
use App\Domain\Accounting\Posting\Exceptions\ControlAccountProtectedException;
use App\Domain\Accounting\Posting\Exceptions\ImmutableJournalException;
use App\Domain\Accounting\Posting\Exceptions\UnbalancedJournalException;
use App\Domain\Accounting\Posting\Services\PostingEngine;
use App\Domain\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Journal\CreateJournalEntryRequest;
use App\Http\Requests\Journal\UpdateJournalEntryRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

class JournalController extends Controller
{
    public function __construct(private readonly PostingEngine $postingEngine)
    {
    }

    private function getAuthorizedOrganization(Request $request, string $orgId): ?Organization
    {
        return $request->user()?->organizations()
            ->where('organizations.id', $orgId)
            ->first();
    }

    private function canCreateJournal(Request $request, Organization $organization): bool
    {
        $user = $request->user();
        $role = $user->roleInOrganization($organization);

        if (in_array($role, ['owner', 'admin', 'accountant', 'finance_manager'])) {
            return true;
        }

        return $user->hasPermissionInOrganization('accounting.journal.create', $organization);
    }

    private function canPostJournal(Request $request, Organization $organization): bool
    {
        $user = $request->user();
        $role = $user->roleInOrganization($organization);

        if (in_array($role, ['owner', 'admin', 'accountant', 'finance_manager'])) {
            return true;
        }

        return $user->hasPermissionInOrganization('accounting.journal.post', $organization);
    }

    /**
     * List journal entries for the organization.
     */
    public function index(Request $request, string $orgId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);

        if (! $organization) {
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

        $query = JournalEntry::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->with(['lines.account:id,code,name,classification', 'period:id,name,status']);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('period_id')) {
            $query->where('accounting_period_id', $request->query('period_id'));
        }

        if ($request->filled('from_date')) {
            $query->where('entry_date', '>=', $request->query('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->where('entry_date', '<=', $request->query('to_date'));
        }

        $entries = $query->orderBy('entry_date', 'desc')->orderBy('entry_number', 'desc')->get();

        return response()->json([
            'data' => $entries,
            'meta' => [
                'total' => $entries->count(),
                'organization_id' => $organization->id,
                'timestamp' => now()->toISOString(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Create a draft (or auto-posted) journal entry.
     */
    public function store(CreateJournalEntryRequest $request, string $orgId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);

        if (! $organization) {
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

        if (! $this->canCreateJournal($request, $organization)) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'FORBIDDEN',
                        'message' => 'You do not have permission to create journal entries.',
                    ],
                ],
            ], 403);
        }

        try {
            $entry = $this->postingEngine->createDraft($organization, $request->validated(), $request->user());

            if ($request->boolean('auto_post')) {
                if (! $this->canPostJournal($request, $organization)) {
                    return response()->json([
                        'data' => null,
                        'meta' => ['timestamp' => now()->toISOString()],
                        'errors' => [
                            [
                                'code' => 'FORBIDDEN',
                                'message' => 'You do not have permission to post journal entries.',
                            ],
                        ],
                    ], 403);
                }

                $entry = $this->postingEngine->postEntry($entry, $request->user());
            }

            return response()->json([
                'data' => $entry,
                'meta' => [
                    'message' => $entry->isPosted() ? 'Journal entry created and posted' : 'Draft journal entry created',
                    'timestamp' => now()->toISOString(),
                ],
                'errors' => [],
            ], 201);
        } catch (UnbalancedJournalException|ClosedPeriodException|ControlAccountProtectedException|InvalidArgumentException $e) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'ACCOUNTING_RULE_VIOLATION',
                        'message' => $e->getMessage(),
                    ],
                ],
            ], 422);
        }
    }

    /**
     * Show a journal entry with lines and audit metadata.
     */
    public function show(Request $request, string $orgId, string $journalId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);

        if (! $organization) {
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

        $entry = JournalEntry::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->with(['lines.account', 'period', 'postedByUser:id,name,email', 'reversalOf', 'reversals'])
            ->findOrFail($journalId);

        return response()->json([
            'data' => $entry,
            'meta' => [
                'is_balanced' => $entry->isBalanced(),
                'total_debit' => $entry->totalDebit(),
                'total_credit' => $entry->totalCredit(),
                'timestamp' => now()->toISOString(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Update an unposted draft journal entry.
     */
    public function update(UpdateJournalEntryRequest $request, string $orgId, string $journalId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);

        if (! $organization) {
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

        if (! $this->canCreateJournal($request, $organization)) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'FORBIDDEN',
                        'message' => 'You do not have permission to edit journal entries.',
                    ],
                ],
            ], 403);
        }

        $entry = JournalEntry::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->findOrFail($journalId);

        try {
            $updated = $this->postingEngine->updateDraft($entry, $request->validated(), $request->user());

            return response()->json([
                'data' => $updated,
                'meta' => [
                    'message' => 'Draft journal entry updated',
                    'timestamp' => now()->toISOString(),
                ],
                'errors' => [],
            ]);
        } catch (ImmutableJournalException $e) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'JOURNAL_IMMUTABLE',
                        'message' => $e->getMessage(),
                    ],
                ],
            ], 422);
        } catch (ControlAccountProtectedException|InvalidArgumentException $e) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'ACCOUNTING_RULE_VIOLATION',
                        'message' => $e->getMessage(),
                    ],
                ],
            ], 422);
        }
    }

    /**
     * Post a draft journal entry to the General Ledger.
     */
    public function post(Request $request, string $orgId, string $journalId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);

        if (! $organization) {
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

        if (! $this->canPostJournal($request, $organization)) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'FORBIDDEN',
                        'message' => 'You do not have permission to post journal entries.',
                    ],
                ],
            ], 403);
        }

        $entry = JournalEntry::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->findOrFail($journalId);

        try {
            $posted = $this->postingEngine->postEntry($entry, $request->user());

            return response()->json([
                'data' => $posted,
                'meta' => [
                    'message' => "Journal entry {$posted->entry_number} successfully posted to General Ledger",
                    'timestamp' => now()->toISOString(),
                ],
                'errors' => [],
            ]);
        } catch (UnbalancedJournalException|ClosedPeriodException|ImmutableJournalException|InvalidArgumentException $e) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'POSTING_FAILED',
                        'message' => $e->getMessage(),
                    ],
                ],
            ], 422);
        }
    }

    /**
     * Reverse a posted journal entry.
     */
    public function reverse(Request $request, string $orgId, string $journalId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);

        if (! $organization) {
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

        if (! $this->canPostJournal($request, $organization)) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'FORBIDDEN',
                        'message' => 'You do not have permission to reverse journal entries.',
                    ],
                ],
            ], 403);
        }

        $entry = JournalEntry::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->findOrFail($journalId);

        try {
            $reversal = $this->postingEngine->createReversal(
                $entry,
                $request->user(),
                $request->input('reason')
            );

            return response()->json([
                'data' => $reversal,
                'meta' => [
                    'message' => "Reversal entry {$reversal->entry_number} created and posted successfully",
                    'timestamp' => now()->toISOString(),
                ],
                'errors' => [],
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'REVERSAL_FAILED',
                        'message' => $e->getMessage(),
                    ],
                ],
            ], 422);
        }
    }
}
