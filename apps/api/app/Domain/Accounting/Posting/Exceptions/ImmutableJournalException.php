<?php

namespace App\Domain\Accounting\Posting\Exceptions;

use RuntimeException;

class ImmutableJournalException extends RuntimeException
{
    public function __construct(string $entryNumber)
    {
        parent::__construct(sprintf(
            'Journal entry "%s" is posted and immutable. Corrections must be made via a reversal entry.',
            $entryNumber
        ));
    }
}
