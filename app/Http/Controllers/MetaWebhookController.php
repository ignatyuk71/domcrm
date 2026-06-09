<?php

namespace App\Http\Controllers;

use App\Services\Meta\MetaWebhookProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Ендпоінт вебхука Meta.
 *  - GET  — верифікація підписки (hub.challenge).
 *  - POST — вхідні події (повідомлення FB/IG) → у inbox_* через процесор.
 *
 * Примітка: Meta шле hub.mode / hub.verify_token / hub.challenge;
 * PHP конвертує крапки в підкреслення → читаємо hub_mode і т.д.
 */
class MetaWebhookController extends Controller
{
    public function __construct(private MetaWebhookProcessor $processor)
    {
    }

    public function __invoke(Request $request)
    {
        if ($request->isMethod('get')) {
            $verifyToken = (string) config('services.meta.verify_token');
            $sent = (string) $request->input('hub_verify_token');

            if ($request->input('hub_mode') === 'subscribe'
                && $verifyToken !== ''
                && hash_equals($verifyToken, $sent)) {
                return response((string) $request->input('hub_challenge'), 200);
            }

            return response('Forbidden', 403);
        }

        // Перевірка підпису. Поки що м'яко: при невдачі логуємо, але обробляємо.
        // (Зробимо суворо — відхиляти — перед запуском на реальних клієнтів.)
        if (!$this->processor->verifySignature($request->getContent(), $request->header('X-Hub-Signature-256'))) {
            Log::warning('Meta webhook: підпис не співпав (поки обробляємо все одно)');
        }

        try {
            $count = $this->processor->process($request->all());
            if ($count > 0) {
                Log::info('Meta webhook: збережено повідомлень', ['count' => $count]);
            }
        } catch (\Throwable $e) {
            Log::error('Meta webhook: помилка обробки', ['error' => $e->getMessage()]);
        }

        // Завжди 200 — інакше Meta ретраїтиме.
        return response('EVENT_RECEIVED', 200);
    }
}
