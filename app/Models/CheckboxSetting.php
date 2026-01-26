<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class CheckboxSetting extends Model
{
    protected $fillable = [
        'api_url',
        'license_key',
        'login',
        'password',
        'enabled',
        'queue_enabled',
        'open_time',
        'close_time',
        'queue_process_time',
        'last_opened_at',
        'last_closed_at',
        'last_queue_processed_at',
        'updated_by',
    ];

    protected $casts = [
        'license_key' => 'encrypted',
        'password' => 'encrypted',
        'enabled' => 'boolean',
        'queue_enabled' => 'boolean',
        'last_opened_at' => 'datetime',
        'last_closed_at' => 'datetime',
        'last_queue_processed_at' => 'datetime',
    ];

    public static function current(): ?self
    {
        return static::query()->first();
    }

    public static function resolveCheckboxConnection(): array
    {
        $defaults = [
            'api_url' => config('services.checkbox.api_url', 'https://api.checkbox.in.ua/api/v1'),
            'license_key' => config('services.checkbox.license_key'),
            'login' => config('services.checkbox.login'),
            'password' => config('services.checkbox.password'),
        ];

        try {
            $settings = static::current();
        } catch (\Throwable $e) {
            return $defaults;
        }

        if (!$settings) {
            return $defaults;
        }

        return [
            'api_url' => $settings->api_url ?: $defaults['api_url'],
            'license_key' => $settings->license_key ?: $defaults['license_key'],
            'login' => $settings->login ?: $defaults['login'],
            'password' => $settings->password ?: $defaults['password'],
        ];
    }

    public function isWithinWindow(Carbon $now): bool
    {
        [$openAt, $closeAt] = $this->windowTimes($now);
        return $now->between($openAt, $closeAt, true);
    }

    public function windowTimes(Carbon $now): array
    {
        $timezone = $now->getTimezone();
        $openAt = $this->timeToToday($this->open_time ?? '08:00:00', $now, $timezone);
        $closeAt = $this->timeToToday($this->close_time ?? '23:00:00', $now, $timezone);

        return [$openAt, $closeAt];
    }

    public function queueProcessAt(Carbon $now): Carbon
    {
        return $this->timeToToday($this->queue_process_time ?? '08:30:00', $now, $now->getTimezone());
    }

    public function nextQueueAvailableAt(Carbon $now): Carbon
    {
        [$openAt, $closeAt] = $this->windowTimes($now);
        $queueAt = $this->queueProcessAt($now);

        if ($now->lessThan($openAt)) {
            return $queueAt;
        }

        if ($now->greaterThanOrEqualTo($closeAt)) {
            return $queueAt->addDay();
        }

        return $queueAt;
    }

    private function timeToToday(string $time, Carbon $now, \DateTimeZone $timezone): Carbon
    {
        $parts = explode(':', $time);
        $hour = (int) ($parts[0] ?? 0);
        $minute = (int) ($parts[1] ?? 0);
        $second = (int) ($parts[2] ?? 0);

        return Carbon::create(
            $now->year,
            $now->month,
            $now->day,
            $hour,
            $minute,
            $second,
            $timezone
        );
    }
}
