<?php

namespace App\Exceptions;

use RuntimeException;

class FiscalShiftUnavailableException extends RuntimeException
{
    public readonly string $shiftStatus;

    public function __construct(?string $shiftStatus = null)
    {
        $status = strtoupper($shiftStatus ?? 'UNKNOWN');
        $this->shiftStatus = in_array($status, ['CLOSED', 'OPENING', 'CLOSING'], true) ? $status : 'UNKNOWN';

        parent::__construct(match ($this->shiftStatus) {
            'CLOSED' => 'Каса закрита',
            'OPENING' => 'Каса відкривається',
            'CLOSING' => 'Каса закривається',
            default => 'Не вдалося перевірити стан каси. Повторіть перевірку.',
        });
    }

    public function httpStatus(): int
    {
        return $this->shiftStatus === 'UNKNOWN' ? 503 : 409;
    }

    public function payload(): array
    {
        return [
            'available' => false,
            'shift_status' => $this->shiftStatus,
            'code' => $this->shiftStatus === 'CLOSED' ? 'fiscal_shift_closed' : 'fiscal_shift_unavailable',
            'message' => $this->getMessage(),
        ];
    }
}
