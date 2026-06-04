<?php

namespace App\Http\Middleware;

use App\Models\OrderSource;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Автентифікує вхідний запит зовнішнього сайту за API-ключем (+ HMAC-підписом, якщо заданий секрет).
 * Знайдене джерело кладемо в request attributes як 'external_source'.
 */
class VerifyExternalSource
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-Api-Key') ?: $request->bearerToken();

        if (!$key) {
            return response()->json(['message' => 'Відсутній API-ключ.'], 401);
        }

        $source = OrderSource::query()
            ->where('is_integration', true)
            ->where('api_key', $key)
            ->first();

        if (!$source) {
            return response()->json(['message' => 'Невірний API-ключ.'], 401);
        }

        if (!$source->is_enabled) {
            return response()->json(['message' => 'Інтеграцію вимкнено.'], 403);
        }

        // HMAC-підпис тіла запиту (якщо для джерела заданий секрет).
        if (!empty($source->api_secret)) {
            $signature = (string) $request->header('X-Signature');
            $expected = hash_hmac('sha256', $request->getContent(), $source->api_secret);

            if ($signature === '' || !hash_equals($expected, $signature)) {
                return response()->json(['message' => 'Невірний підпис запиту.'], 401);
            }
        }

        $request->attributes->set('external_source', $source);

        return $next($request);
    }
}
