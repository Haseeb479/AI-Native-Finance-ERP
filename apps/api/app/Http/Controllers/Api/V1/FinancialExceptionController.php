<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Exceptions\Services\FinancialExceptionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialExceptionController extends Controller
{
    public function __construct(
        protected FinancialExceptionService $exceptionService
    ) {}

    /**
     * List open financial exceptions for organization (P3-01).
     */
    public function index(Request $request, string $orgId): JsonResponse
    {
        $type = $request->query('type');
        $exceptions = $this->exceptionService->getOpenExceptions($orgId, $type);

        return response()->json([
            'data' => [
                'exceptions' => $exceptions,
                'total_count' => $exceptions->count(),
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
            'errors' => [],
        ], 200);
    }

    /**
     * Raise a new financial exception (P3-01).
     */
    public function store(Request $request, string $orgId): JsonResponse
    {
        $validated = $request->validate([
            'exception_type' => ['required', 'string', 'in:' . implode(',', FinancialExceptionService::VALID_TYPES)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'payload' => ['nullable', 'array'],
            'severity' => ['nullable', 'string', 'in:low,medium,high,critical'],
            'aggregate_type' => ['nullable', 'string'],
            'aggregate_id' => ['nullable', 'string'],
        ]);

        $exception = $this->exceptionService->raiseException(
            organization: $orgId,
            type: $validated['exception_type'],
            title: $validated['title'],
            description: $validated['description'] ?? null,
            payload: $validated['payload'] ?? [],
            severity: $validated['severity'] ?? 'medium',
            aggregateType: $validated['aggregate_type'] ?? null,
            aggregateId: $validated['aggregate_id'] ?? null,
        );

        return response()->json([
            'data' => $exception,
            'meta' => ['timestamp' => now()->toIso8601String()],
            'errors' => [],
        ], 201);
    }

    /**
     * Resolve a financial exception with notes (P3-01).
     */
    public function resolve(Request $request, string $orgId, string $id): JsonResponse
    {
        $request->validate([
            'notes' => ['required', 'string', 'min:3'],
        ]);

        $resolved = $this->exceptionService->resolveException($id, $request->user(), $request->notes);

        return response()->json([
            'data' => [
                'message' => 'Financial exception resolved successfully.',
                'exception' => $resolved,
            ],
            'meta' => ['timestamp' => now()->toIso8601String()],
            'errors' => [],
        ], 200);
    }
}
