<?php

namespace App\Services\NovaPay;

use Carbon\CarbonImmutable;

class RefreshSchedule
{
    public function times(): array
    {
        $times = config('novapay.refresh_times');
        sort($times);

        return $times;
    }

    public function data(?CarbonImmutable $at = null): array
    {
        $timezone = config('novapay.timezone');
        $now = ($at ?? CarbonImmutable::now($timezone))->setTimezone($timezone);
        $times = $this->times();
        $today = array_map(fn ($time) => $now->setTimeFromTimeString($time)->startOfMinute(), $times);
        $previous = $today[array_key_last($today)]->subDay();
        $next = $today[0]->addDay();

        foreach ($today as $slot) {
            if ($slot <= $now) {
                $previous = $slot;
            } else {
                $next = $slot;
                break;
            }
        }

        return [
            'times' => $times,
            'timezone' => $timezone,
            'previous_at' => $previous->utc()->toIso8601String(),
            'next_at' => $next->utc()->toIso8601String(),
            'grace_minutes' => config('novapay.refresh_grace_minutes'),
        ];
    }
}
