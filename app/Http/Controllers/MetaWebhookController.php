<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Ендпоінт вебхука Meta.
 * Фаза підключення: лише верифікація (GET) + лог вхідних подій (POST).
 * Повноцінну обробку повідомлень додамо у фазі чату.
 *
 * Примітка: Meta шле параметри hub.mode / hub.verify_token / hub.challenge,
 * PHP конвертує крапки в підкреслення → читаємо hub_mode і т.д.
 */
class MetaWebhookController extends Controller
{
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

        // POST — поки лише логуємо (обробка у фазі чату).
        Log::info('Meta webhook event', $request->all());

        return response('EVENT_RECEIVED', 200);
    }
}
