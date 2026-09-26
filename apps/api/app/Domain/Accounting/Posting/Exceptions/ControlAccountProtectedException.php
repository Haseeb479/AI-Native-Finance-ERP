<?php

namespace App\Domain\Accounting\Posting\Exceptions;

use RuntimeException;

class ControlAccountProtectedException extends RuntimeException
{
    public function __construct(string $accountCode, string $accountName, string $controlType = 'subledger')
    {
        parent::__construct(sprintf(
            'Direct manual journal posting to control account [%s - %s] is prohibited. This %s control account may only be mutated through authorized subledger documents (invoices, bills, or payments).',
            $accountCode,
            $accountName,
            $controlType
        ));
    }
}
