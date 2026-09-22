<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Organization\Models\Organization;
use App\Domain\Purchasing\Models\PurchaseBill;
use App\Domain\Purchasing\Services\BillService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\CreatePurchaseBillRequest;
use App\Http\Requests\Purchasing\RecordBillPaymentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PurchaseBillController extends Controller
{
    public function __construct(private readonly BillService $billService)
    {
    }

    private function getAuthorizedOrganization(Request $request, string $orgId): ?Organization
    {
        return $request->user()?->organizations()
            ->where('organizations.id', $orgId)
            ->first();
    }

    private function canCreateBill(Request $request, Organization $organization): bool
    {
        $user = $request->user();
        $role = $user->roleInOrganization($organization);

        if (in_array($role, ['owner', 'admin', 'accountant', 'finance_manager', 'staff'])) {
            return true;
        }

        return $user->hasPermissionInOrganization('purchases.bill.create', $organization);
    }

    private function canPostBill(Request $request, Organization $organization): bool
    {
        $user = $request->user();
        $role = $user->roleInOrganization($organization);

        if (in_array($role, ['owner', 'admin', 'accountant', 'finance_manager'])) {
            return true;
        }

        return $user->hasPermissionInOrganization('purchases.bill.post', $organization);
    }

    /**
     * List purchase bills.
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

        $query = PurchaseBill::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->with(['vendor:id,name,email,ntn', 'lines']);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->query('vendor_id'));
        }

        if ($request->filled('from_date')) {
            $query->where('bill_date', '>=', $request->query('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->where('bill_date', '<=', $request->query('to_date'));
        }

        $bills = $query->orderBy('bill_date', 'desc')->orderBy('bill_number', 'desc')->get();

        $data = $bills->map(function ($bill) {
            $arr = $bill->toArray();
            $arr['balance_due'] = $bill->balanceDue();
            $arr['is_overdue'] = $bill->isOverdue();
            return $arr;
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $bills->count(),
                'organization_id' => $organization->id,
                'timestamp' => now()->toISOString(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Create a purchase bill (draft or auto-posted).
     */
    public function store(CreatePurchaseBillRequest $request, string $orgId): JsonResponse
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

        if (! $this->canCreateBill($request, $organization)) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'FORBIDDEN',
                        'message' => 'You do not have permission to create purchase bills.',
                    ],
                ],
            ], 403);
        }

        try {
            $bill = $this->billService->createBill($organization, $request->validated(), $request->user());

            if ($request->boolean('auto_post')) {
                if (! $this->canPostBill($request, $organization)) {
                    return response()->json([
                        'data' => null,
                        'meta' => ['timestamp' => now()->toISOString()],
                        'errors' => [
                            [
                                'code' => 'FORBIDDEN',
                                'message' => 'You do not have permission to post purchase bills.',
                            ],
                        ],
                    ], 403);
                }

                $bill = $this->billService->postBill($bill, $request->user());
            }

            $arr = $bill->toArray();
            $arr['balance_due'] = $bill->balanceDue();

            return response()->json([
                'data' => $arr,
                'meta' => [
                    'message' => $bill->isPosted() ? 'Purchase bill created and posted to General Ledger' : 'Draft purchase bill created',
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
                        'code' => 'BILL_CREATION_FAILED',
                        'message' => $e->getMessage(),
                    ],
                ],
            ], 422);
        }
    }

    /**
     * Show purchase bill details.
     */
    public function show(Request $request, string $orgId, string $billId): JsonResponse
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

        $bill = PurchaseBill::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->with(['vendor', 'lines.expenseAccount', 'journalEntry.lines.account'])
            ->findOrFail($billId);

        $data = $bill->toArray();
        $data['balance_due'] = $bill->balanceDue();
        $data['is_overdue'] = $bill->isOverdue();

        return response()->json([
            'data' => $data,
            'meta' => [
                'timestamp' => now()->toISOString(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Post a draft purchase bill to the General Ledger.
     */
    public function post(Request $request, string $orgId, string $billId): JsonResponse
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

        if (! $this->canPostBill($request, $organization)) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'FORBIDDEN',
                        'message' => 'You do not have permission to post purchase bills.',
                    ],
                ],
            ], 403);
        }

        $bill = PurchaseBill::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->findOrFail($billId);

        try {
            $posted = $this->billService->postBill($bill, $request->user());

            $data = $posted->toArray();
            $data['balance_due'] = $posted->balanceDue();

            return response()->json([
                'data' => $data,
                'meta' => [
                    'message' => "Bill {$posted->bill_number} successfully posted to General Ledger",
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
                        'code' => 'BILL_POST_FAILED',
                        'message' => $e->getMessage(),
                    ],
                ],
            ], 422);
        }
    }

    /**
     * Record a payment disbursement to the vendor.
     */
    public function recordPayment(RecordBillPaymentRequest $request, string $orgId, string $billId): JsonResponse
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

        if (! $this->canPostBill($request, $organization)) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toISOString()],
                'errors' => [
                    [
                        'code' => 'FORBIDDEN',
                        'message' => 'You do not have permission to record vendor payments.',
                    ],
                ],
            ], 403);
        }

        $bill = PurchaseBill::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->findOrFail($billId);

        try {
            $updated = $this->billService->recordPayment($bill, $request->validated(), $request->user());

            $data = $updated->toArray();
            $data['balance_due'] = $updated->balanceDue();

            return response()->json([
                'data' => $data,
                'meta' => [
                    'message' => "Payment of PKR {$request->validated('amount')} disbursed successfully for Bill {$bill->bill_number}",
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
}
