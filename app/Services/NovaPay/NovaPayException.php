<?php

namespace App\Services\NovaPay;

use RuntimeException;

class NovaPayException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly bool $retryable = false)
    {
        // Не додаємо вихідний виняток: його тіло може містити токени або реквізити.
        parent::__construct($message);
    }
}
