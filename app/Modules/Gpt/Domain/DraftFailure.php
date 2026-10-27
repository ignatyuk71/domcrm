<?php

namespace App\Modules\Gpt\Domain;

use RuntimeException;

class DraftFailure extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
