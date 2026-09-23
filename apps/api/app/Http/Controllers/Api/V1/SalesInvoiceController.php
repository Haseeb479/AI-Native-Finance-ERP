<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Organization\Models\Organization;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Sales\Services\InvoiceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\CreateSalesInvoiceRequest;
use App\Http\Requests\Sales\RecordPaymentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class SalesInvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoiceService)
    {
    }

    private function getAuthorizedOrganization(Request $request, string $orgId): ?Organization
    {
        return $request->user()?->organizations()
            ->where('organizations.id', $orgId)
            ->first();
    }

    private function canCreateInvoice(Request $request, Organization $organization): bool
    {
        $user = $request->user();
        $role = $user->roleInOrganization($organization);

        if (in_array($role, ['owner', 'admin', 'accountant', 'finance_manager', 'staff'])) {
            return true;
        }

        return $user->hasPermissionInOrganization('sales.invoice.create', $organization);
    }

    private function canPostInvoice(Request $request, Organization $organization): bool
    {
        $user = $request->user();
        $role = $user->roleInOrganization($organization);

        if (in_array($role, ['owner', 'admin', 'accountant', 'finance_manager'])) {
            return true;
        }

        return $user->hasPermissionInOrganization('sales.invoice.post', $organization);
    }

    private function canApproveInvoice(Request $request, Organization $organization): bool
    {
        $user = $request->user();
        $role = $user->roleInOrganization($organization);

        if (in_array($role, ['owner', 'admin', 'accountant', 'finance_manager'])) {
            return true;
        }

        return $user->hasPermissionInOrganization('sales.invoice.approve', $organization);
    }

    /**
     * List sales invoices.
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

        $query = SalesInvoice::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->with(['customer:id,name,email,ntn', 'lines']);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->query('customer_id'));
        }

        if ($request->filled('from_date')) {
            $query->where('issue_date', '>=', $request->query('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->where('issue_date', '<=', $request->query('to_date'));
        }

        $invoices = $query->orderBy('issue_date', 'desc')->orderBy('invoice_number', 'desc')->get();

        $data = $invoices->map(function ($inv) {
            $arr = $inv->toArray();
            $arr['balance_due'] = $inv->balanceDue();
            $arr['is_overdue'] = $inv->isOverdue();
            return $arr;
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $invoices->count(),
                'organization_id' => $organization->id,
                'timestamp' => now()->toISOString(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Create a sales invoice (draft or auto-posted).
     */
    public function store(CreateSalesInvoiceRequest $request, string $orgId): JsonResponse
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

        if (! $this->canCreateInvoice($request, $organization)) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'FORBIDDEN',
                        'message' => 'You do not have permission to create sales invoices.',
                    ],
                ],
            ], 403);
        }

        try {
            $invoice = $this->invoiceService->createInvoice($organization, $request->validated(), $request->user());

            if ($request->boolean('auto_post')) {
                if (! $this->canPostInvoice($request, $organization)) {
                    return response()->json([
                        'data' => null,
                        'meta' => ['timestamp' => now()->toISOString()],
                        'errors' => [
                            [
                                'code' => 'FORBIDDEN',
                                'message' => 'You do not have permission to post sales invoices.',
                            ],
                        ],
                    ], 403);
                }

                $invoice = $this->invoiceService->postInvoice($invoice, $request->user());
            }

            $arr = $invoice->toArray();
            $arr['balance_due'] = $invoice->balanceDue();

            return response()->json([
                'data' => $arr,
                'meta' => [
                    'message' => $invoice->isPosted() ? 'Sales invoice created and posted to General Ledger' : 'Draft sales invoice created',
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
                        'code' => 'INVOICE_CREATION_FAILED',
                        'message' => $e->getMessage(),
                    ],
                ],
            ], 422);
        }
    }

    /**
     * Show sales invoice details.
     */
    public function show(Request $request, string $orgId, string $invoiceId): JsonResponse
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

        $invoice = SalesInvoice::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->with(['customer', 'lines.revenueAccount', 'journalEntry.lines.account'])
            ->findOrFail($invoiceId);

        $data = $invoice->toArray();
        $data['balance_due'] = $invoice->balanceDue();
        $data['is_overdue'] = $invoice->isOverdue();

        return response()->json([
            'data' => $data,
            'meta' => [
                'timestamp' => now()->toISOString(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Post a draft sales invoice to the General Ledger.
     */
    public function post(Request $request, string $orgId, string $invoiceId): JsonResponse
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

        if (! $this->canPostInvoice($request, $organization)) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'FORBIDDEN',
                        'message' => 'You do not have permission to post sales invoices.',
                    ],
                ],
            ], 403);
        }

        $invoice = SalesInvoice::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->findOrFail($invoiceId);

        try {
            $posted = $this->invoiceService->postInvoice($invoice, $request->user());

            $data = $posted->toArray();
            $data['balance_due'] = $posted->balanceDue();

            return response()->json([
                'data' => $data,
                'meta' => [
                    'message' => "Invoice {$posted->invoice_number} successfully posted to General Ledger",
                    'timestamp' => now()->toISOString(),
                ],
                'errors' => [],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'INVOICE_POST_FAILED',
                        'message' => $e->getMessage(),
                    ],
                ],
            ], 422);
        }
    }

    /**
     * Record a customer payment against an invoice.
     */
    public function recordPayment(RecordPaymentRequest $request, string $orgId, string $invoiceId): JsonResponse
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

        if (! $this->canPostInvoice($request, $organization)) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'FORBIDDEN',
                        'message' => 'You do not have permission to record customer payments.',
                    ],
                ],
            ], 403);
        }

        $invoice = SalesInvoice::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->findOrFail($invoiceId);

        try {
            $updated = $this->invoiceService->recordPayment($invoice, $request->validated(), $request->user());

            $data = $updated->toArray();
            $data['balance_due'] = $updated->balanceDue();

            return response()->json([
                'data' => $data,
                'meta' => [
                    'message' => "Payment of PKR {$request->validated('amount')} recorded successfully for Invoice {$invoice->invoice_number}",
                    'timestamp' => now()->toISOString(),
                ],
                'errors' => [],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'PAYMENT_RECORD_FAILED',
                        'message' => $e->getMessage(),
                    ],
                ],
            ], 422);
        }
    }

    /**
     * Submit invoice for approval.
     */
    public function submit(Request $request, string $orgId, string $invoiceId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);
        if (! $organization) return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'NOT_FOUND', 'message' => 'Organization not found']]], 404);

        $invoice = SalesInvoice::withoutGlobalScopes()->where('organization_id', $organization->id)->findOrFail($invoiceId);

        try {
            $updated = $this->invoiceService->submitForApproval($invoice, $request->user());
            return response()->json([
                'data' => $updated,
                'meta' => ['message' => "Invoice {$updated->invoice_number} submitted for approval."],
                'errors' => [],
            ]);
        } catch (Throwable $e) {
            return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'SUBMIT_FAILED', 'message' => $e->getMessage()]]], 422);
        }
    }

    /**
     * Approve invoice.
     */
    public function approve(Request $request, string $orgId, string $invoiceId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);
        if (! $organization) return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'NOT_FOUND', 'message' => 'Organization not found']]], 404);

        if (! $this->canApproveInvoice($request, $organization)) {
            return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'FORBIDDEN', 'message' => 'You do not have permission to approve sales invoices.']]], 403);
        }

        $invoice = SalesInvoice::withoutGlobalScopes()->where('organization_id', $organization->id)->findOrFail($invoiceId);

        try {
            $updated = $this->invoiceService->approveInvoice($invoice, $request->user());
            return response()->json([
                'data' => $updated,
                'meta' => ['message' => "Invoice {$updated->invoice_number} approved successfully."],
                'errors' => [],
            ]);
        } catch (Throwable $e) {
            return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'APPROVE_FAILED', 'message' => $e->getMessage()]]], 422);
        }
    }

    /**
     * Reject invoice with reason.
     */
    public function reject(Request $request, string $orgId, string $invoiceId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);
        if (! $organization) return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'NOT_FOUND', 'message' => 'Organization not found']]], 404);

        if (! $this->canApproveInvoice($request, $organization)) {
            return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'FORBIDDEN', 'message' => 'You do not have permission to reject sales invoices.']]], 403);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $invoice = SalesInvoice::withoutGlobalScopes()->where('organization_id', $organization->id)->findOrFail($invoiceId);

        try {
            $updated = $this->invoiceService->rejectInvoice($invoice, $request->user(), $validated['reason']);
            return response()->json([
                'data' => $updated,
                'meta' => ['message' => "Invoice {$updated->invoice_number} rejected."],
                'errors' => [],
            ]);
        } catch (Throwable $e) {
            return response()->json(['data' => null, 'meta' => [], 'errors' => [['code' => 'REJECT_FAILED', 'message' => $e->getMessage()]]], 422);
        }
    }
}
