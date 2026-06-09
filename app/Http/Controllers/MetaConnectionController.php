<?php

namespace App\Http\Controllers;

use App\Models\MetaConnection;
use App\Services\Meta\MetaOAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MetaConnectionController extends Controller
{
    public function __construct(private MetaOAuthService $meta)
    {
    }

    /** Сторінка списку підключень. */
    public function index()
    {
        $connections = MetaConnection::query()->latest()->get();
        $configured = $this->meta->isConfigured();
        $redirectUri = $this->meta->redirectUri();

        return view('settings.meta', compact('connections', 'configured', 'redirectUri'));
    }

    /** Старт OAuth — редірект на Facebook. */
    public function connect(Request $request)
    {
        if (!$this->meta->isConfigured()) {
            return redirect()->route('settings.meta.index')
                ->with('error', 'Не задані META_APP_ID / META_APP_SECRET у .env.');
        }

        $state = Str::random(40);
        $request->session()->put('meta_oauth_state', $state);

        return redirect()->away($this->meta->authUrl($state));
    }

    /** Колбек від Facebook після згоди користувача. */
    public function callback(Request $request)
    {
        if ($request->filled('error')) {
            return redirect()->route('settings.meta.index')
                ->with('error', 'Facebook відмовив: '.(string) $request->input('error_description'));
        }

        // Захист від CSRF: state має збігтись із тим, що поклали перед редіректом.
        $expected = $request->session()->pull('meta_oauth_state');
        $state = (string) $request->input('state');
        if (!$expected || $state === '' || !hash_equals($expected, $state)) {
            return redirect()->route('settings.meta.index')
                ->with('error', 'Невірний state (захист CSRF). Спробуй ще раз.');
        }

        $code = (string) $request->input('code');
        if ($code === '') {
            return redirect()->route('settings.meta.index')
                ->with('error', 'Facebook не повернув код авторизації.');
        }

        try {
            $userToken = $this->meta->exchangeCodeForLongLivedToken($code);
            $me = $this->meta->getMe($userToken);
            $pages = $this->meta->getPages($userToken);
        } catch (\Throwable $e) {
            return redirect()->route('settings.meta.index')
                ->with('error', 'Помилка обміну з Facebook: '.$e->getMessage());
        }

        if (empty($pages)) {
            return redirect()->route('settings.meta.index')
                ->with('error', 'Не знайдено жодної сторінки. Переконайся, що ти адмін сторінки і надав дозволи.');
        }

        $scopes = array_values(array_filter(explode(',', (string) config('services.meta.scopes'))));
        $connected = 0;

        foreach ($pages as $page) {
            if (empty($page['id']) || empty($page['access_token'])) {
                continue;
            }

            $ig = $page['instagram_business_account'] ?? null;
            $subscribed = $this->meta->subscribePageWebhook($page['id'], $page['access_token']);

            MetaConnection::updateOrCreate(
                ['page_id' => (string) $page['id']],
                [
                    'page_name' => $page['name'] ?? (string) $page['id'],
                    'page_access_token' => $page['access_token'],
                    'ig_account_id' => $ig['id'] ?? null,
                    'ig_username' => $ig['username'] ?? null,
                    'fb_user_id' => $me['id'] ?? null,
                    'scopes' => $scopes,
                    'webhook_subscribed' => $subscribed,
                    'status' => 'active',
                    'last_error' => $subscribed ? null : 'Сторінку підключено, але підписка на вебхук не вдалась.',
                    'connected_by' => $request->user()?->id,
                ]
            );
            $connected++;
        }

        return redirect()->route('settings.meta.index')
            ->with('success', "Підключено сторінок: {$connected}.");
    }

    /** Перевірка, що токен сторінки ще живий. */
    public function test(MetaConnection $connection)
    {
        $info = $this->meta->getPageInfo($connection->page_id, $connection->page_access_token);

        if (!$info) {
            $connection->update(['status' => 'error', 'last_error' => 'Токен недійсний або немає доступу.']);
            return redirect()->route('settings.meta.index')
                ->with('error', "«{$connection->page_name}»: токен недійсний.");
        }

        $ig = $info['instagram_business_account'] ?? null;
        $connection->update([
            'page_name' => $info['name'] ?? $connection->page_name,
            'ig_account_id' => $ig['id'] ?? $connection->ig_account_id,
            'ig_username' => $ig['username'] ?? $connection->ig_username,
            'status' => 'active',
            'last_error' => null,
        ]);

        $igText = !empty($ig['username']) ? " • IG @{$ig['username']}" : '';
        return redirect()->route('settings.meta.index')
            ->with('success', "🟢 «{$info['name']}» — токен живий{$igText}.");
    }

    /** Відключити сторінку — мʼяко (без видалення), щоб зберегти id і історію листування. */
    public function disconnect(MetaConnection $connection)
    {
        $connection->update(['status' => 'disconnected']);

        return redirect()->route('settings.meta.index')
            ->with('success', "Сторінку «{$connection->page_name}» відключено (історію збережено; повторне підключення її відновить).");
    }
}
