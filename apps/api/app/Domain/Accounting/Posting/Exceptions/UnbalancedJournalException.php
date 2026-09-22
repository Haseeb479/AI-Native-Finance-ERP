<?php

namespace App\Domain\Accounting\Posting\Exceptions;

use RuntimeException;

class UnbalancedJournalException extends RuntimeException
{
    public function __construct(float $debit, float $credit)
    {
        parent::__construct(sprintf(
            'Journal entry is not balanced. Total Debits: PKR %.4f, Total Credits: PKR %.4f (Difference: PKR %.4f).',
            $debit,
            $credit,
            abs($debit - $credit)
        ));
    }
}
