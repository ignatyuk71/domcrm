<?php

namespace App\Console\Commands;

use App\Models\CheckboxSetting;
use App\Services\CheckboxService;
use App\Services\FiscalQueueService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ManageFiscalShift extends Command
{
    protected $signature = 'fiscal:shift-manager';
    protected $description = 'Автоматичне відкриття/закриття зміни та запуск черги фіскалізації';

    public function handle(CheckboxService $checkbox, FiscalQueueService $queueService): int
    {
        $cronLog = Log::channel('cron_fiscal');
        $settings = CheckboxSetting::current();

        if (!$settings || !$settings->enabled) {
            $this->info('Fiscal settings disabled or missing.');
            return self::SUCCESS;
        }

        $now = Carbon::now(config('app.timezone', 'Europe/Kyiv'));
        [$openAt, $closeAt] = $settings->windowTimes($now);
        $queueAt = $settings->queueProcessAt($now);
        $withinWindow = $now->between($openAt, $closeAt, true);

        if ($now->greaterThanOrEqualTo($openAt) && $now->lessThan($closeAt)) {
            $shouldOpen = !$settings->last_opened_at || !$settings->last_opened_at->isSameDay($now);
            if ($shouldOpen) {
                $ok = $checkbox->openShift();
                if ($ok) {
                    $settings->update(['last_opened_at' => $now]);
                    $cronLog->info('Checkbox shift opened by schedule.');
                } else {
                    $cronLog->error('Checkbox shift open failed by schedule.');
                }
            }
        }

        if ($now->greaterThanOrEqualTo($closeAt)) {
            $shouldClose = !$settings->last_closed_at || !$settings->last_closed_at->isSameDay($now);
            if ($shouldClose) {
                $ok = $checkbox->closeShift();
                if ($ok) {
                    $settings->update(['last_closed_at' => $now]);
                    $cronLog->info('Checkbox shift closed by schedule.');
                } else {
                    $cronLog->error('Checkbox shift close failed by schedule.');
                }
            }
        }

        // Обробляємо чергу тільки у вікні роботи та не раніше часу фіскалізації
        if ($settings->queue_enabled && $withinWindow && $now->greaterThanOrEqualTo($queueAt)) {
            $processed = $queueService->processAvailable();
            if ($processed > 0) {
                $settings->update(['last_queue_processed_at' => $now]);
                $cronLog->info("Fiscal queue processed: {$processed} items.");
            }
        }

        return self::SUCCESS;
    }
}
