<?php

namespace App\Services\Meta;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Уся робота з Facebook Graph API для підключення сторінок (OAuth).
 * Без обробки повідомлень — лише підключення/токени/підписка на вебхук.
 */
class MetaOAuthService
{
    private string $appId;
    private string $appSecret;
    private string $graphVersion;
    private string $scopes;

    public function __construct()
    {
        $this->appId = (string) config('services.meta.app_id');
        $this->appSecret = (string) config('services.meta.app_secret');
        $this->graphVersion = (string) config('services.meta.graph_version', 'v21.0');
        $this->scopes = (string) config('services.meta.scopes');
    }

    public function isConfigured(): bool
    {
        return $this->appId !== '' && $this->appSecret !== '';
    }

    private function graph(): string
    {
        return "https://graph.facebook.com/{$this->graphVersion}";
    }

    /** Канонічний redirect_uri — однаковий і в діалозі, і в обміні токена. */
    public function redirectUri(): string
    {
        return route('settings.meta.callback');
    }

    /** URL діалогу Facebook Login. */
    public function authUrl(string $state): string
    {
        $query = http_build_query([
            'client_id' => $this->appId,
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'scope' => $this->scopes,
            'response_type' => 'code',
        ]);

        return "https://www.facebook.com/{$this->graphVersion}/dialog/oauth?{$query}";
    }

    /** Код → довготривалий токен користувача (~60 днів). Кидає виняток при помилці. */
    public function exchangeCodeForLongLivedToken(string $code): string
    {
        $short = Http::get($this->graph().'/oauth/access_token', [
            'client_id' => $this->appId,
            'client_secret' => $this->appSecret,
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
        ]);

        if (!$short->successful() || !$short->json('access_token')) {
            throw new \RuntimeException('Не вдалося отримати токен: '.$short->body());
        }

        $long = Http::get($this->graph().'/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->appId,
            'client_secret' => $this->appSecret,
            'fb_exchange_token' => $short->json('access_token'),
        ]);

        // Якщо довготривалий не повернувся — лишаємо короткий.
        return (string) ($long->json('access_token') ?: $short->json('access_token'));
    }

    /** Інфо про користувача, що підключає. */
    public function getMe(string $userToken): array
    {
        $r = Http::get($this->graph().'/me', [
            'fields' => 'id,name',
            'access_token' => $userToken,
        ]);

        return $r->successful() ? ($r->json() ?? []) : [];
    }

    /** Сторінки користувача з токенами та звʼязаним Instagram. */
    public function getPages(string $userToken): array
    {
        $r = Http::get($this->graph().'/me/accounts', [
            'fields' => 'id,name,access_token,instagram_business_account{id,username}',
            'access_token' => $userToken,
        ]);

        if (!$r->successful()) {
            Log::error('Meta getPages failed', ['body' => $r->body()]);
            return [];
        }

        return $r->json('data') ?? [];
    }

    /** Підписати сторінку на вебхуки застосунку (щоб у фазі чату йшли повідомлення). */
    public function subscribePageWebhook(string $pageId, string $pageToken): bool
    {
        $r = Http::asForm()->post($this->graph()."/{$pageId}/subscribed_apps", [
            'subscribed_fields' => 'messages,messaging_postbacks,messaging_optins,message_deliveries,message_reads,messaging_referrals',
            'access_token' => $pageToken,
        ]);

        if (!$r->successful()) {
            Log::warning('Meta subscribePageWebhook failed', ['page' => $pageId, 'body' => $r->body()]);
            return false;
        }

        return (bool) $r->json('success');
    }

    /** Перевірка токена сторінки (кнопка «Перевірити»). Повертає інфо або null. */
    public function getPageInfo(string $pageId, string $pageToken): ?array
    {
        $r = Http::get($this->graph()."/{$pageId}", [
            'fields' => 'id,name,instagram_business_account{id,username}',
            'access_token' => $pageToken,
        ]);

        return $r->successful() ? $r->json() : null;
    }

    /** Імʼя користувача (best-effort) для відображення в інбоксі. */
    public function getUserName(string $pageToken, string $userId): ?string
    {
        try {
            $r = Http::timeout(5)->get($this->graph()."/{$userId}", [
                'fields' => 'name',
                'access_token' => $pageToken,
            ]);

            return $r->successful() ? ($r->json('name') ?: null) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
