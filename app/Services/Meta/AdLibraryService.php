<?php

namespace App\Services\Meta;

use App\Models\MetaConnection;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class AdLibraryService
{
    public const COUNTRIES = [
        'PL' => 'Польща',
        'DE' => 'Німеччина',
        'CZ' => 'Чехія',
        'UA' => 'Україна (пошук на сайті Meta)',
    ];

    public function check(?MetaConnection $connection, string $search, string $country): array
    {
        $pageToken = '';
        $page = ['state' => 'missing', 'message' => 'Сторінку Facebook не обрано.'];

        if ($connection) {
            try {
                $pageToken = (string) $connection->page_access_token;
                $page = $pageToken !== ''
                    ? $this->checkPage($connection, $pageToken)
                    : ['state' => 'missing', 'message' => 'У підключенні немає токена сторінки.'];
            } catch (DecryptException) {
                $page = ['state' => 'error', 'message' => 'Не вдалося прочитати токен. Підключіть сторінку повторно в налаштуваннях Meta.'];
            }
        }

        $userToken = trim((string) config('meta_ad_library.access_token'));
        $tokenSource = $userToken !== '' ? 'user' : ($pageToken !== '' ? 'page' : 'none');
        $token = $userToken !== '' ? $userToken : $pageToken;

        // Звичайна комерційна реклама України доступна через веббібліотеку.
        if ($country === 'UA') {
            $ads = ['state' => 'web_only', 'message' => 'Для комерційної реклами в Україні скористайтеся кнопкою «Відкрити в Meta». Для пробного API-запиту оберіть Польщу, Німеччину або Чехію.'];
        } elseif ($token === '') {
            $ads = ['state' => 'missing', 'message' => 'Немає токена для перевірки. Підключіть Facebook або налаштуйте окремий доступ до Ad Library.'];
        } else {
            $ads = $this->search($token, $search, $country);
        }

        return [
            'checked_at' => now()->toIso8601String(),
            'query' => ['search' => $search, 'country' => $country, 'connection_id' => $connection?->id],
            'page' => $page,
            'library' => array_merge(['ads' => [], 'token_source' => $tokenSource], $ads),
        ];
    }

    private function checkPage(MetaConnection $connection, string $token): array
    {
        $response = $this->get((string) $connection->page_id, $token, ['fields' => 'id,name']);

        if ($response instanceof Response && $response->successful() && $response->json('id') === $connection->page_id) {
            return [
                'state' => 'connected',
                'id' => (string) $response->json('id'),
                'name' => (string) $response->json('name'),
                'message' => 'Meta підтвердила доступ до сторінки. Ця перевірка не надсилає повідомлень і не перевіряє доставку вебхуків.',
            ];
        }

        return $this->failure($response, $token);
    }

    private function search(string $token, string $search, string $country): array
    {
        $response = $this->get('ads_archive', $token, [
            'search_terms' => $search,
            'ad_reached_countries' => json_encode([$country]),
            'ad_type' => 'ALL',
            'ad_active_status' => 'ACTIVE',
            'fields' => 'id,page_id,page_name,ad_creative_bodies,ad_creative_link_titles,ad_delivery_start_time,publisher_platforms',
            'limit' => 6,
        ]);

        if (! $response instanceof Response || ! $response->successful() || $response->json('error')) {
            return $this->failure($response, $token);
        }

        $data = $response->json('data');
        if (! is_array($data)) {
            return ['state' => 'error', 'message' => 'Meta повернула неочікувану відповідь без списку реклами. Спробуйте повторити перевірку.'];
        }

        // Віддаємо лише дозволені поля; paging і snapshot можуть містити токен.
        $ads = collect($data)->filter(fn ($ad) => is_array($ad) && preg_match('/^\d+$/', (string) ($ad['id'] ?? '')))
            ->take(6)->map(fn (array $ad) => [
                'id' => (string) $ad['id'],
                'page_name' => (string) ($ad['page_name'] ?? 'Рекламодавець'),
                'body' => (string) ($ad['ad_creative_bodies'][0] ?? ''),
                'title' => (string) ($ad['ad_creative_link_titles'][0] ?? ''),
                'started_at' => $ad['ad_delivery_start_time'] ?? null,
                'platforms' => array_values(array_filter($ad['publisher_platforms'] ?? [], 'is_string')),
                'url' => 'https://www.facebook.com/ads/library/?id='.rawurlencode((string) $ad['id']),
            ])->values()->all();

        return [
            'state' => 'connected',
            'message' => $ads
                ? 'Ad Library відповідає. Нижче — до 6 активних оголошень за вашим запитом.'
                : 'Ad Library відповідає, але за цим запитом оголошень не знайдено. Спробуйте інше слово або країну; це не означає відсутність реклами на ринку.',
            'ads' => $ads,
        ];
    }

    private function get(string $path, string $token, array $query): ?Response
    {
        try {
            // Авторизація в заголовку не потрапляє в URL та історію браузера.
            return Http::withToken($token)->acceptJson()->connectTimeout(4)->timeout(10)
                ->withOptions(['allow_redirects' => false])
                ->get('https://graph.facebook.com/'.config('services.meta.graph_version', 'v21.0').'/'.$path, $query);
        } catch (ConnectionException) {
            // Текст винятку може містити технічні дані запиту, тому його не повертаємо.
            return null;
        }
    }

    private function failure(?Response $response, string $token): array
    {
        if (! $response) {
            return ['state' => 'unavailable', 'message' => 'Не вдалося з’єднатися з Meta вчасно. Перевірте мережу та повторіть запит.'];
        }

        $code = (int) $response->json('error.code');
        $temporary = $response->serverError() || $response->status() === 429
            || in_array($code, [1, 2, 4, 17, 32, 613], true) || $response->json('error.is_transient') === true;
        $message = match (true) {
            $temporary => 'Meta тимчасово недоступна або обмежила кількість запитів. Повторіть перевірку пізніше.',
            $code === 190 => 'Meta відхилила токен: термін дії минув або токен недійсний.',
            in_array($code, [10, 200], true) => 'Meta не надала доступ до цього ресурсу. Для Ad Library може знадобитися окремий токен користувача та реєстрація доступу.',
            default => 'Meta не підтвердила доступ. Перевірте відповідь сервісу та налаштування доступу.',
        };

        // Зберігаємо контекст помилки, але прибираємо секрети та токени з URL.
        $detail = (string) ($response->json('error.error_user_msg') ?: $response->json('error.message'));
        $secrets = array_filter([$token, (string) config('services.meta.app_secret'), (string) config('meta_ad_library.access_token')]);
        $detail = str_replace($secrets, '[приховано]', $detail);
        $detail = preg_replace('/((?:access_token|appsecret_proof|client_secret)=)[^\s&"\'<>]+/i', '$1[приховано]', $detail);

        return [
            'state' => $temporary ? 'unavailable' : ($code === 190 ? 'invalid_token' : 'error'),
            'message' => $message,
            'detail' => mb_substr($detail, 0, 1000),
            'error_code' => $code ?: null,
            'error_subcode' => $response->json('error.error_subcode'),
            'http_status' => $response->status(),
        ];
    }
}
