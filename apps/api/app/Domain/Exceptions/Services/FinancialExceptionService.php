<?php

namespace App\Domain\Exceptions\Services;

use App\Domain\Exceptions\Models\FinancialException;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use InvalidArgumentException;

class FinancialExceptionService
{
    public const VALID_TYPES = [
        'unmatched_bank_transaction',
        'duplicate_invoice',
        'low_ocr_confidence',
        'missing_document',
        'tax_mismatch',
        'unusual_expense',
        'integration_failure',
        'revenue_exception',
        'closed_period_conflict',
    ];

    /**
     * Raise a first-class financial exception into the workflow queue (P3-01).
     */
    public function raiseException(
        Organization|string $organization,
        string $type,
        string $title,
        ?string $description = null,
        array $payload = [],
        string $severity = 'medium',
        ?string $aggregateType = null,
        ?string $aggregateId = null,
        ?string $entityId = null,
    ): FinancialException {
        if (! in_array($type, self::VALID_TYPES, true)) {
            throw new InvalidArgumentException("Invalid financial exception type: {$type}");
        }

        $orgId = is_string($organization) ? $organization : $organization->id;

        return FinancialException::create([
            'organization_id' => $orgId,
            'entity_id' => $entityId,
            'exception_type' => $type,
            'severity' => $severity,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'title' => $title,
            'description' => $description,
            'payload' => $payload,
            'status' => 'open',
        ]);
    }

    /**
     * Resolve an exception with auditor notes.
     */
    public function resolveException(string $exceptionId, User $user, string $notes): FinancialException
    {
        $exception = FinancialException::findOrFail($exceptionId);
        $exception->resolve($user, $notes);

        return $exception;
    }

    /**
     * Get open exception queue for organization.
     */
    public function getOpenExceptions(Organization|string $organization, ?string $type = null)
    {
        $orgId = is_string($organization) ? $organization : $organization->id;

        $query = FinancialException::where('organization_id', $orgId)
            ->whereIn('status', ['open', 'under_review'])
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
            ->orderBy('created_at', 'desc');

        if ($type) {
            $query->where('exception_type', $type);
        }

        return $query->get();
    }
}
