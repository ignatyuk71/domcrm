<?php

namespace App\Services;

use App\Models\MetaConnection;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class MetaConnectionService
{
    public function current(): ?MetaConnection
    {
        return MetaConnection::current();
    }

    public function isConfigured(): bool
    {
        return $this->appId() !== '' && $this->appSecret() !== '';
    }

    public function callbackUrl(): string
    {
        return route('settings.meta.callback');
    }

    public function webhookUrl(): string
    {
        return url('/api/fb-webhook');
    }

    public function redirectUrl(): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('META_APP_ID або META_APP_SECRET не налаштовані.');
        }

        $query = http_build_query([
            'client_id' => $this->appId(),
            'redirect_uri' => $this->callbackUrl(),
            'scope' => implode(',', $this->scopes()),
            'response_type' => 'code',
            'state' => $this->generateState(),
            'auth_type' => 'rerequest',
            'return_scopes' => 'true',
        ]);

        return 'https://www.facebook.com/' . $this->graphVersion() . '/dialog/oauth?' . $query;
    }

    public function saveConnectionSettings(array $data): MetaConnection
    {
        $connection = $this->current() ?? new MetaConnection([
            'provider' => 'meta',
            'app_id' => $this->appId(),
            'is_active' => true,
        ]);

        $connection->fill([
            'name' => Arr::get($data, 'name'),
            'verify_token' => Arr::get($data, 'verify_token') ?: ($connection->verify_token ?: Str::random(40)),
            'webhook_secret' => Arr::get($data, 'webhook_secret') ?: ($connection->webhook_secret ?: Str::random(64)),
        ]);
        $connection->app_id = $this->appId();
        $connection->save();

        return $connection->fresh();
    }

    public function connectFromAuthorizationCode(string $code, User $user): MetaConnection
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('META_APP_ID або META_APP_SECRET не налаштовані.');
        }

        $shortLivedToken = $this->exchangeCodeForUserToken($code);
        $longLivedToken = $this->exchangeForLongLivedUserToken($shortLivedToken['access_token']);
        $userToken = $longLivedToken['access_token'] ?? $shortLivedToken['access_token'];
        $userTokenExpiresAt = $this->resolveExpiresAt($longLivedToken['expires_in'] ?? $shortLivedToken['expires_in'] ?? null);
        $grantedScopes = $this->fetchGrantedScopes($userToken);
        $missingScopes = $this->missingRequiredScopes($grantedScopes);

        $metaUser = $this->fetchMetaUser($userToken);
        $pages = $this->fetchPages($userToken);
        $selectedPage = $this->selectPage($pages);
        $previousConnection = $this->current();

        if (!$selectedPage) {
            throw new RuntimeException('У вибраному акаунті не знайдено Facebook-сторінку для підключення.');
        }

        $instagram = $selectedPage['instagram_business_account']
            ?? $selectedPage['connected_instagram_account']
            ?? [];

        MetaConnection::query()
            ->where('provider', 'meta')
            ->update(['is_active' => false]);

        $connection = MetaConnection::query()->updateOrCreate(
            ['facebook_page_id' => (string) ($selectedPage['id'] ?? '')],
            [
                'provider' => 'meta',
                'name' => $selectedPage['name'] ?? 'Meta підключення',
                'app_id' => $this->appId(),
                'meta_user_id' => (string) ($metaUser['id'] ?? ''),
                'meta_user_name' => $metaUser['name'] ?? null,
                'facebook_page_name' => $selectedPage['name'] ?? null,
                'instagram_account_id' => Arr::get($instagram, 'id'),
                'instagram_username' => Arr::get($instagram, 'username') ?: Arr::get($instagram, 'name'),
                'business_account_id' => Arr::get($instagram, 'id'),
                'user_access_token' => $userToken,
                'user_token_expires_at' => $userTokenExpiresAt,
                'access_token' => $selectedPage['access_token'] ?? null,
                'token_type' => $shortLivedToken['token_type'] ?? 'bearer',
                'token_expires_at' => null,
                'granted_scopes' => $grantedScopes !== [] ? $grantedScopes : $this->scopes(),
                'verify_token' => $previousConnection?->verify_token ?: Str::random(40),
                'webhook_secret' => $previousConnection?->webhook_secret ?: Str::random(64),
                'webhook_fields' => $this->webhookFields(),
                'webhook_subscribed' => false,
                'is_active' => true,
                'connected_at' => now(),
                'last_token_refresh_at' => now(),
                'last_error' => $missingScopes === []
                    ? null
                    : 'Meta не видала обов’язкові дозволи: ' . implode(', ', $missingScopes) . '. Перепідключіть інтеграцію та підтвердьте всі запити.',
                'profile_payload' => [
                    'meta_user' => $metaUser,
                    'selected_page' => $selectedPage,
                    'pages' => $pages,
                    'granted_scopes' => $grantedScopes,
                    'missing_scopes' => $missingScopes,
                ],
                'connected_by' => $user->id,
            ]
        );

        try {
            $connection = $this->syncWebhookSubscription($connection);
        } catch (\Throwable $e) {
            $connection->update([
                'webhook_subscribed' => false,
                'last_error' => 'Не вдалося підписати сторінку на webhook: ' . $e->getMessage(),
            ]);
        }

        return $connection->fresh();
    }

    public function disconnect(): void
    {
        $connection = $this->current();
        if (!$connection) {
            return;
        }

        $connection->update([
            'is_active' => false,
            'last_error' => null,
        ]);
    }

    public function syncWebhookSubscription(?MetaConnection $connection = null): MetaConnection
    {
        $connection = $connection ?: $this->current();

        if (!$connection) {
            throw new RuntimeException('Активне Meta-підключення не знайдено.');
        }

        if (!$connection->facebook_page_id || !$connection->access_token) {
            throw new RuntimeException('Для підписки webhook бракує page_id або page access token.');
        }

        $fields = $this->webhookFields();

        $response = Http::withToken((string) $connection->access_token)
            ->asForm()
            ->post($this->graphApiUrl('/' . $connection->facebook_page_id . '/subscribed_apps'), [
                'subscribed_fields' => implode(',', $fields),
            ]);

        if (!$response->successful()) {
            throw new RuntimeException(
                $response->json('error.message')
                    ?: 'Meta не підтвердила підписку сторінки на webhook.'
            );
        }

        $connection->update([
            'webhook_subscribed' => true,
            'webhook_fields' => $fields,
            'last_error' => $this->normalizeSubscriptionErrorState((string) $connection->last_error),
        ]);

        return $connection->fresh();
    }

    private function exchangeCodeForUserToken(string $code): array
    {
        $response = Http::get($this->graphApiUrl('/oauth/access_token'), [
            'client_id' => $this->appId(),
            'redirect_uri' => $this->callbackUrl(),
            'client_secret' => $this->appSecret(),
            'code' => $code,
        ]);

        if (!$response->successful()) {
            throw new RuntimeException($response->json('error.message') ?: 'Не вдалося отримати user access token.');
        }

        return $response->json();
    }

    private function exchangeForLongLivedUserToken(string $shortLivedToken): array
    {
        $response = Http::get($this->graphApiUrl('/oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->appId(),
            'client_secret' => $this->appSecret(),
            'fb_exchange_token' => $shortLivedToken,
        ]);

        if (!$response->successful()) {
            return [];
        }

        return $response->json();
    }

    private function fetchMetaUser(string $accessToken): array
    {
        $response = Http::withToken($accessToken)->get($this->graphApiUrl('/me'), [
            'fields' => 'id,name',
        ]);

        if (!$response->successful()) {
            throw new RuntimeException($response->json('error.message') ?: 'Не вдалося отримати дані Meta користувача.');
        }

        return $response->json();
    }

    private function fetchPages(string $accessToken): array
    {
        $response = Http::withToken($accessToken)->get($this->graphApiUrl('/me/accounts'), [
            'fields' => 'id,name,access_token,instagram_business_account{id,username,name},connected_instagram_account{id,username,name}',
            'limit' => 100,
        ]);

        if (!$response->successful()) {
            throw new RuntimeException($response->json('error.message') ?: 'Не вдалося отримати список Facebook-сторінок.');
        }

        return $response->json('data') ?? [];
    }

    private function fetchGrantedScopes(string $accessToken): array
    {
        $response = Http::withToken($accessToken)->get($this->graphApiUrl('/me/permissions'));

        if (!$response->successful()) {
            return [];
        }

        $granted = [];
        foreach ($response->json('data', []) as $permission) {
            if (($permission['status'] ?? null) !== 'granted') {
                continue;
            }

            $name = trim((string) ($permission['permission'] ?? ''));
            if ($name !== '') {
                $granted[] = $name;
            }
        }

        return array_values(array_unique($granted));
    }

    private function missingRequiredScopes(array $grantedScopes): array
    {
        if ($grantedScopes === []) {
            return [];
        }

        return array_values(array_diff($this->requiredScopes(), $grantedScopes));
    }

    private function selectPage(array $pages): ?array
    {
        foreach ($pages as $page) {
            if (!empty($page['instagram_business_account']['id']) || !empty($page['connected_instagram_account']['id'])) {
                return $page;
            }
        }

        return $pages[0] ?? null;
    }

    private function resolveExpiresAt(mixed $expiresIn): mixed
    {
        if (!is_numeric($expiresIn) || (int) $expiresIn <= 0) {
            return null;
        }

        return now()->addSeconds((int) $expiresIn);
    }

    private function generateState(): string
    {
        $state = Str::uuid()->toString();
        session()->put('meta_oauth_state', $state);

        return $state;
    }

    private function appId(): string
    {
        return (string) config('services.meta.app_id', '');
    }

    private function appSecret(): string
    {
        return (string) config('services.meta.app_secret', '');
    }

    private function graphVersion(): string
    {
        return (string) config('services.meta.graph_version', 'v19.0');
    }

    private function scopes(): array
    {
        $scopes = config('services.meta.scopes', []);

        if (is_string($scopes)) {
            $scopes = array_map('trim', explode(',', $scopes));
        }

        return array_values(array_filter($scopes));
    }

    private function webhookFields(): array
    {
        return [
            'messages',
            'message_deliveries',
            'message_reads',
            'messaging_postbacks',
            'messaging_optins',
            'feed',
        ];
    }

    private function requiredScopes(): array
    {
        return array_values(array_filter([
            'pages_show_list',
            'pages_messaging',
            'pages_manage_metadata',
            'pages_read_engagement',
            'instagram_basic',
            'instagram_manage_messages',
        ]));
    }

    private function graphApiUrl(string $path): string
    {
        return 'https://graph.facebook.com/' . $this->graphVersion() . $path;
    }

    private function normalizeSubscriptionErrorState(string $lastError): ?string
    {
        $normalized = trim($lastError);
        if ($normalized === '') {
            return null;
        }

        return str_starts_with($normalized, 'Не вдалося підписати сторінку на webhook:')
            ? null
            : $normalized;
    }
}
