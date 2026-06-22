<?php

namespace App\Services\Ai;

/**
 * Графік роботи агента. Винесено з AiAgentService: чиста логіка часового вікна.
 */
class AiSchedule
{
    /**
     * Чи дозволяє графік працювати зараз. schedule: null/['mode'=>'always'] —
     * цілодобово; ['mode'=>'window','from'=>'20:00','to'=>'09:00'] — вікно
     * активності (через північ підтримується).
     */
    public static function allows(?array $schedule, ?\Carbon\Carbon $at = null): bool
    {
        if (($schedule['mode'] ?? 'always') !== 'window') {
            return true;
        }
        $from = $schedule['from'] ?? '00:00';
        $to = $schedule['to'] ?? '24:00';
        $now = ($at ?? now())->format('H:i');

        return $from <= $to
            ? ($now >= $from && $now < $to)
            : ($now >= $from || $now < $to); // вікно через північ, напр. 20:00–09:00
    }
}
