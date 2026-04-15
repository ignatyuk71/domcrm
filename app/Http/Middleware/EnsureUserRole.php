<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserRole
{
    /**
     * @param  array<int, string>  $roles
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response|RedirectResponse|JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            abort(401);
        }

        if (!$user->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Ваш акаунт деактивовано.'], 403);
            }

            return redirect()->route('login')->withErrors([
                'email' => 'Ваш акаунт деактивовано. Зверніться до власника CRM.',
            ]);
        }

        if (!empty($roles) && !in_array($user->roleKey(), $roles, true)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Недостатньо прав доступу.'], 403);
            }

            abort(403, 'Недостатньо прав доступу.');
        }

        return $next($request);
    }
}
