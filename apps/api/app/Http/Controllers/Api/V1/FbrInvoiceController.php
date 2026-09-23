<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Organization\Models\Organization;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Taxation\Pakistan\Services\FbrInvoiceService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class FbrInvoiceController extends Controller
{
    public function __construct(private readonly FbrInvoiceService $fbrInvoiceService)
    {
    }

    private function getAuthorizedOrganization(Request $request, string $orgId): ?Organization
    {
        return $request->user()?->organizations()
            ->where('organizations.id', $orgId)
            ->first();
    }

    private function canFiscalize(Request $request, Organization $organization): bool
    {
        $user = $request->user();
        $role = $user->roleInOrganization($organization);

        if (in_array($role, ['owner', 'admin', 'accountant', 'finance_manager'])) {
            return true;
        }

        return $user->hasPermissionInOrganization('sales.invoice.post', $organization);
    }

    /**
     * Fiscalize an invoice with FBR Digital Invoicing.
     */
    public function fiscalize(Request $request, string $orgId, string $invoiceId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);

        if (! $organization) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => [
                    [
                        'code' => 'ORGANIZATION_NOT_FOUND',
                        'message' => 'Organization not found or access denied.',
                    ],
                ],
            ], 404);
        }

        if (! $this->canFiscalize($request, $organization)) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => [
                    [
                        'code' => 'FORBIDDEN',
                        'message' => 'You do not have permission to fiscalize invoices with FBR.',
                    ],
                ],
            ], 403);
        }

        $invoice = SalesInvoice::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->with(['customer', 'lines'])
            ->findOrFail($invoiceId);

        try {
            $options = $request->only(['pos_id']);
            $fiscalizedInvoice = $this->fbrInvoiceService->fiscalizeInvoice($invoice, $request->user(), $options);

            return response()->json([
                'data' => [
                    'id' => $fiscalizedInvoice->id,
                    'invoice_number' => $fiscalizedInvoice->invoice_number,
                    'fbr_invoice_number' => $fiscalizedInvoice->fbr_invoice_number,
                    'fbr_status' => $fiscalizedInvoice->fbr_status,
                    'fbr_fiscalized_at' => $fiscalizedInvoice->fbr_fiscalized_at?->toIso8601String(),
                    'fbr_qr_code' => $fiscalizedInvoice->fbr_qr_code,
                    'fbr_response_data' => $fiscalizedInvoice->fbr_response_data,
                    'total_amount' => $fiscalizedInvoice->total_amount,
                    'tax_amount' => $fiscalizedInvoice->tax_amount,
                ],
                'meta' => [
                    'message' => "Invoice {$fiscalizedInvoice->invoice_number} successfully fiscalized with FBR (FBR No: {$fiscalizedInvoice->fbr_invoice_number}).",
                    'timestamp' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => [
                    [
                        'code' => 'FBR_FISCALIZE_FAILED',
                        'message' => $e->getMessage(),
                    ],
                ],
            ], 422);
        }
    }

    /**
     * Get FBR QR Code data and verification parameters.
     */
    public function getQrCode(Request $request, string $orgId, string $invoiceId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);

        if (! $organization) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
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
            ->with(['customer', 'lines'])
            ->findOrFail($invoiceId);

        $qrData = $this->fbrInvoiceService->getQrCodeData($invoice);

        return response()->json([
            'data' => $qrData,
            'meta' => [
                'organization_id' => $organization->id,
                'timestamp' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Validate Pakistan tax identifier (NTN, STRN, CNIC).
     */
    public function validateTaxId(Request $request, string $orgId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);

        if (! $organization) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => [
                    [
                        'code' => 'ORGANIZATION_NOT_FOUND',
                        'message' => 'Organization not found or access denied.',
                    ],
                ],
            ], 404);
        }

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:ntn,strn,cnic,NTN,STRN,CNIC'],
            'tax_id' => ['required', 'string', 'max:50'],
        ]);

        try {
            $result = $this->fbrInvoiceService->validateTaxId($validated['type'], $validated['tax_id']);

            return response()->json([
                'data' => $result,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => [],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => [
                    [
                        'code' => 'VALIDATION_FAILED',
                        'message' => $e->getMessage(),
                    ],
                ],
            ], 422);
        }
    }

    /**
     * Get Pakistan tax & FBR fiscalization summary.
     */
    public function summary(Request $request, string $orgId): JsonResponse
    {
        $organization = $this->getAuthorizedOrganization($request, $orgId);

        if (! $organization) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
                'errors' => [
                    [
                        'code' => 'ORGANIZATION_NOT_FOUND',
                        'message' => 'Organization not found or access denied.',
                    ],
                ],
            ], 404);
        }

        $summary = $this->fbrInvoiceService->getFiscalizationSummary($organization);

        return response()->json([
            'data' => $summary,
            'meta' => [
                'organization_id' => $organization->id,
                'timestamp' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }
}
