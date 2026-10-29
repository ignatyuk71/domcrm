<?php

namespace App\Modules\GptSandbox\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PrivateSandboxResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('gptSandbox.index', 'gptSandbox.image')) {
            // Валідація ключа завжди повертає JSON і не записує його у flash-сесію.
            $request->headers->set('Accept', 'application/json');
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
