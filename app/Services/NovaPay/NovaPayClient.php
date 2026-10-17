<?php

namespace App\Services\NovaPay;

use App\Models\NovaPayAccount;
use App\Models\NovaPayConnection;
use DOMElement;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class NovaPayClient
{
    public function accounts(NovaPayConnection $connection): array
    {
        $result = $this->request($connection, 'GetClientsList');
        $accounts = [];
        foreach (Xml::nodes($result, 'Clients') as $client) {
            $clientId = Xml::value($client, 'id');
            if (! ctype_digit($clientId) || (int) $clientId < 1) {
                throw new NovaPayException('invalid_identity', 'NovaPay повернув некоректний ідентифікатор підприємства.');
            }
            $response = $this->request($connection, 'GetAccountsList', ['client_id' => $clientId]);
            foreach (Xml::nodes($response, 'Accounts') as $account) {
                $id = Xml::value($account, 'id');
                $iban = strtoupper(Xml::value($account, 'IBAN'));
                $currency = strtoupper(Xml::value($account, 'currency'));
                if (! ctype_digit($id) || (int) $id < 1 || ! preg_match('/^UA\d{27}$/D', $iban)) {
                    throw new NovaPayException('invalid_identity', 'NovaPay повернув некоректні реквізити рахунку.');
                }
                // На першому етапі синхронізуємо лише гривневі рахунки.
                if ($currency !== 'UAH') {
                    continue;
                }
                $accounts[] = [
                    'provider_id' => (int) $id, 'client_id' => (int) $clientId,
                    'client_name' => Xml::value($client, 'name'), 'iban' => $iban, 'currency' => $currency,
                ];
            }
        }

        return $accounts;
    }

    public function balance(NovaPayAccount $account): array
    {
        $result = $this->request($account->connection, 'GetAccountRest', ['account_id' => $account->provider_id]);
        $balances = [];
        foreach (['available', 'confirmed', 'projected'] as $type) {
            $balances[$type] = Money::minor(Xml::value($result, $type.'_balance'));
        }

        return $balances;
    }

    public function operations(NovaPayAccount $account, string $from, string $to): array
    {
        if (! config('novapay.operations_verified')) {
            throw new NovaPayException('contract_pending', 'Формат виписки, статуси та повнота API ще потребують перевірки на рахунку.');
        }
        // Список платежів містить поточний день і списання з порожньою PayDate.
        $result = $this->request($account->connection, 'GetPaymentsList', [
            'account_id' => (string) $account->provider_id,
            'date_from' => \Carbon\CarbonImmutable::parse($from)->format('d.m.Y'),
            'date_to' => \Carbon\CarbonImmutable::parse($to)->format('d.m.Y'),
            'date_type' => '0',
        ]);

        return app(ExtractNormalizer::class)->normalize(Xml::value($result, 'payments'), $account, $from, $to);
    }

    public function startPayoutRegister(NovaPayAccount $account, string $from, string $to): int
    {
        $result = $this->request($account->connection, 'GetRegister', [
            'Type' => 1, 'ClientId' => $account->client_id,
            'From' => \Carbon\CarbonImmutable::parse($from)->format('d.m.Y'),
            'Into' => \Carbon\CarbonImmutable::parse($to)->format('d.m.Y'), 'FileExtension' => 'CSV',
        ]);
        $id = Xml::value($result, 'statement_id');
        if (! ctype_digit($id) || (int) $id < 1 || (int) $id > 2147483647) {
            throw new NovaPayException('register_contract', 'NovaPay не повернув ID реєстру післяплати.');
        }

        return (int) $id;
    }

    public function payoutRegister(NovaPayAccount $account, int $id): string
    {
        $result = $this->request($account->connection, 'DownloadRegister', ['Type' => 1, 'Id' => $id]);
        if (Xml::value($result, 'status') !== 'created') {
            throw new NovaPayException('register_pending', 'NovaPay ще формує реєстр післяплати. Перевіримо повторно.', true);
        }
        $url = Xml::value($result, 'url');
        $parts = parse_url($url);
        // Підписане посилання не зберігаємо і не передаємо йому JWT. Дозволене лише перевірене сховище.
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== config('novapay.register_download_host')
            || ! str_starts_with($parts['path'] ?? '', config('novapay.register_download_prefix'))
            || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)
            || Xml::value($result, 'file_type') !== 'CSV') {
            throw new NovaPayException('register_download', 'Непідтверджена адреса або формат реєстру NovaPay.');
        }
        try {
            $response = Http::connectTimeout(5)->timeout(25)->withOptions([
                'allow_redirects' => false,
                'progress' => static function ($total, $received) {
                    if ($total > 10 * 1024 * 1024 || $received > 10 * 1024 * 1024) throw new \RuntimeException;
                },
            ])->get($url);
            if (! $response->successful() || strlen($response->body()) > 10 * 1024 * 1024) throw new \RuntimeException;

            return $response->body();
        } catch (\Throwable) {
            throw new NovaPayException('register_download', 'Не вдалося завантажити реєстр післяплати. Повторимо спробу.', true);
        }
    }

    private function request(NovaPayConnection $connection, string $method, array $params = []): DOMElement
    {
        try {
            return $this->soap($method, ['request_ref' => (string) Str::uuid(), 'jwt' => $this->token($connection), ...$params]);
        } catch (NovaPayException $e) {
            if ($e->reason === 'authorization' && ! $connection->requires_auth) {
                $this->pauseAuthorization($connection, $e);
            }
            throw $e;
        }
    }

    private function token(NovaPayConnection $connection): string
    {
        try {
            return Cache::lock('novapay:auth:'.$connection->id, 45)->block(3, function () use ($connection) {
                $connection->refresh();
                if ($connection->requires_auth) {
                    throw new NovaPayException($connection->auth_error_code ?: 'authorization',
                        $connection->auth_error_message ?: 'Оновлення доступу NovaPay призупинено. Перевірте підключення та історію синхронізації.');
                }
                if ($connection->access_token && $connection->token_expires_at?->isFuture()) {
                    return $connection->access_token;
                }
                try {
                    $result = $this->soap('UserAuthenticationJWT', [
                        'request_ref' => (string) Str::uuid(), 'refresh_token' => $connection->refresh_token,
                        'login' => $connection->login, 'public_certificate' => $connection->public_certificate,
                    ]);
                    $jwt = Xml::value($result, 'jwt');
                    $refresh = Xml::value($result, 'refresh_token');
                    $certificate = Xml::value($result, 'public_certificate');
                    if ($jwt === '' || $refresh === '' || $certificate === '') {
                        throw new NovaPayException('incomplete_response', 'NovaPay повернув неповну відповідь оновлення доступу.');
                    }
                    // Ротацію зберігаємо до інших запитів; старий refresh token може бути вже відкликаний.
                    $connection->forceFill([
                        'refresh_token' => $refresh, 'public_certificate' => $certificate,
                        'access_token' => $jwt, 'token_expires_at' => now()->addSeconds($this->tokenLifetime($jwt)),
                        'auth_error_code' => null, 'auth_error_message' => null,
                    ])->save();

                    return $jwt;
                } catch (NovaPayException $e) {
                    if ($e->reason !== 'rate_limit') {
                        // Втрата відповіді не доводить прострочення токена. Не повторюємо невизначену одноразову ротацію.
                        $failure = $e->reason === 'authorization' ? $e : new NovaPayException('auth_'.$e->reason,
                            'Не вдалося оновити доступ NovaPay. '.$e->getMessage().' Оновлення призупинено до перевірки підключення; необхідність заміни токена не підтверджена.');
                        $this->pauseAuthorization($connection, $failure);
                        throw $failure;
                    }
                    throw $e;
                }
            });
        } catch (LockTimeoutException) {
            throw new NovaPayException('busy', 'Авторизація NovaPay вже оновлюється. Повторимо запит пізніше.', true);
        }
    }

    private function pauseAuthorization(NovaPayConnection $connection, NovaPayException $failure): void
    {
        // Зберігаємо лише безпечну причину, щоб наступні задачі не замінили її загальним повідомленням.
        $connection->forceFill(['requires_auth' => true, 'access_token' => null, 'token_expires_at' => null,
            'auth_error_code' => $failure->reason, 'auth_error_message' => $failure->getMessage()])->save();
    }

    private function tokenLifetime(string $jwt): int
    {
        $parts = explode('.', $jwt);
        $payload = json_decode(base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true);
        $expiry = $payload['exp'] ?? null;

        // Використовуємо строк JWT із запасом хвилина, максимум дві години, без зайвих ротацій кожні 5 хвилин.
        return is_numeric($expiry) ? max(0, min(7200, (int) $expiry - now()->getTimestamp() - 60)) : 0;
    }

    private function soap(string $method, array $params): DOMElement
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $envelope = $document->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 's:Envelope');
        $document->appendChild($envelope);
        $body = $document->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 's:Body');
        $envelope->appendChild($body);
        $operation = $document->createElementNS('http://tempuri.org/', $method);
        $body->appendChild($operation);
        $request = $document->createElementNS('http://tempuri.org/', 'request');
        $operation->appendChild($request);
        foreach ($params as $key => $value) {
            $field = $document->createElementNS('http://tempuri.org/', $key);
            $field->appendChild($document->createTextNode((string) $value));
            $request->appendChild($field);
        }
        try {
            $response = Http::connectTimeout(10)->timeout(config('novapay.timeout'))
                ->withOptions(['allow_redirects' => false])
                ->withHeaders(['SOAPAction' => '"http://tempuri.org/IClientAPIService/'.$method.'"'])
                ->withBody($document->saveXML(), 'text/xml; charset=utf-8')
                ->post(config('novapay.endpoint'));
        } catch (ConnectionException $e) {
            $previous = $e->getPrevious();
            $context = $previous && method_exists($previous, 'getHandlerContext') ? $previous->getHandlerContext() : [];
            $timedOut = ($context['errno'] ?? null) === 28;
            throw new NovaPayException($timedOut ? 'timeout' : 'network',
                $timedOut ? 'NovaPay не відповів у відведений час. Останні отримані дані збережено.'
                    : 'Немає з’єднання з NovaPay. Останні отримані дані збережено.', true);
        }
        if ($response->status() === 429) {
            throw new NovaPayException('rate_limit', 'Перевищено ліміт запитів NovaPay. Оновлення буде повторено пізніше.', true);
        }
        if (in_array($response->status(), [401, 403], true)) {
            throw new NovaPayException('authorization', 'NovaPay відхилив доступ. Перевірте авторизацію підключення.');
        }
        if (! $response->successful()) {
            throw new NovaPayException('provider_http', 'NovaPay повернув HTTP '.$response->status().'. Останні отримані дані збережено.', $response->serverError());
        }
        $xml = Xml::parse($response->body());
        if (Xml::nodes($xml, 'Fault') !== []) {
            throw new NovaPayException('provider_fault', 'NovaPay повернув помилку сервісу. Перевірте доступ або повторіть оновлення.');
        }
        $results = Xml::nodes($xml, $method.'Result');
        if (count($results) !== 1 || Xml::value($results[0], 'result') !== 'ok') {
            throw new NovaPayException('provider_result', 'NovaPay не підтвердив успішність відповіді. Дані не оновлено.');
        }
        if (Xml::value($results[0], 'request_ref') !== '' && Xml::value($results[0], 'request_ref') !== $params['request_ref']) {
            throw new NovaPayException('invalid_response', 'Відповідь NovaPay не відповідає поточному запиту.');
        }

        return $results[0];
    }
}
