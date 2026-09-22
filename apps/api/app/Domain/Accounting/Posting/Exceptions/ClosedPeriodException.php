<?php

namespace App\Domain\Accounting\Posting\Exceptions;

use RuntimeException;

class ClosedPeriodException extends RuntimeException
{
    public function __construct(string $periodName, string $status = 'closed')
    {
        parent::__construct(sprintf(
            'Cannot post journal entry: Accounting period "%s" is %s.',
            $periodName,
            $status
        ));
    }
}
