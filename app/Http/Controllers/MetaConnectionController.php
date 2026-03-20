<?php

namespace App\Http\Controllers;

use App\Services\MetaConnectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MetaConnectionController extends Controller
{
    public function __construct(
        private readonly MetaConnectionService $metaConnectionService
    ) {
    }

    public function index(Request $request)
    {
        $connection = $this->metaConnectionService->current();

        if ($request->expectsJson()) {
            return response()->json([
                'connection' => $connection ? [
                    'id' => $connection->id,
                    'name' => $connection->name,
                    'provider' => $connection->provider,
                    'meta_user_name' => $connection->meta_user_name,
                    'facebook_page_name' => $connection->facebook_page_name,
                    'facebook_page_id' => $connection->facebook_page_id,
                    'instagram_username' => $connection->instagram_username,
                    'instagram_account_id' => $connection->instagram_account_id,
                    'verify_token' => $connection->verify_token,
                    'webhook_secret' => $connection->webhook_secret,
                    'webhook_subscribed' => $connection->webhook_subscribed,
                    'connected_at' => optional($connection->connected_at)?->toIso8601String(),
                    'granted_scopes' => $connection->granted_scopes ?? [],
                    'last_error' => $connection->last_error,
                    'has_page_token' => filled($connection->access_token),
                ] : null,
                'meta' => [
                    'configured' => $this->metaConnectionService->isConfigured(),
                    'connect_url' => route('settings.meta.redirect'),
                    'disconnect_url' => route('settings.meta.disconnect'),
                    'save_url' => route('settings.meta.save'),
                    'callback_url' => $this->metaConnectionService->callbackUrl(),
                    'webhook_url' => $this->metaConnectionService->webhookUrl(),
                    'flash' => [
                        'success' => session('success'),
                        'error' => session('error'),
                    ],
                ],
            ]);
        }

        return view('settings.meta');
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'verify_token' => ['nullable', 'string', 'max:255'],
            'webhook_secret' => ['nullable', 'string', 'max:255'],
        ]);

        $connection = $this->metaConnectionService->saveConnectionSettings($data);

        return response()->json([
            'message' => 'Налаштування Meta збережено.',
            'connection' => $connection,
        ]);
    }

    public function redirectToFacebook()
    {
        return redirect()->away($this->metaConnectionService->redirectUrl());
    }

    public function handleFacebookCallback(Request $request)
    {
        if ($request->filled('error')) {
            return redirect()
                ->route('settings.meta.index')
                ->with('error', $request->string('error_message')->toString() ?: 'Meta авторизацію скасовано.');
        }

        $state = (string) $request->query('state', '');
        $sessionState = (string) $request->session()->pull('meta_oauth_state', '');
        if ($state === '' || $sessionState === '' || !hash_equals($sessionState, $state)) {
            return redirect()
                ->route('settings.meta.index')
                ->with('error', 'Не пройшла перевірка state для Meta авторизації.');
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            return redirect()
                ->route('settings.meta.index')
                ->with('error', 'Meta не повернула authorization code.');
        }

        try {
            $this->metaConnectionService->connectFromAuthorizationCode($code, $request->user());

            return redirect()
                ->route('settings.meta.index')
                ->with('success', 'Meta акаунт успішно підключено.');
        } catch (\Throwable $e) {
            Log::error('Meta OAuth callback error', [
                'message' => $e->getMessage(),
            ]);

            return redirect()
                ->route('settings.meta.index')
                ->with('error', $e->getMessage());
        }
    }

    public function disconnect()
    {
        $this->metaConnectionService->disconnect();

        return response()->json([
            'message' => 'Meta підключення вимкнено.',
        ]);
    }
}
