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
        $jwt = $this->token($connection);
        try {
            return $this->soap($method, ['request_ref' => (string) Str::uuid(), 'jwt' => $jwt, ...$params]);
        } catch (NovaPayException $e) {
            if ($e->reason === 'authorization') {
                try {
                    Cache::lock('novapay:auth:'.$connection->id, 45)->block(3, function () use ($connection, $jwt, $e) {
                        $connection->refresh();
                        // Відмова старого запиту не повинна скасувати нові параметри власника чи іншої задачі.
                        if (! $connection->requires_auth && hash_equals($jwt, $connection->access_token ?? '')) {
                            $this->pauseAuthorization($connection, $e);
                        }
                    });
                } catch (LockTimeoutException) {
                    throw new NovaPayException('busy', 'Авторизація NovaPay вже оновлюється. Повторимо запит пізніше.', true);
                }
            }
            throw $e;
        }
    }

    private function token(NovaPayConnection $connection): string
    {
        try {
            return Cache::lock('novapay:auth:'.$connection->id, 45)->block(3, function () use ($connection) {
                $connection->refresh();
                if ($connection->requires_auth) $this->throwPaused($connection);
                $journal = app(AuthTokenJournal::class);
                $record = $journal->read($connection->id);
                if ($record !== null) {
                    if (! hash_equals($record['base'], $journal->fingerprint($connection))) {
                        // Власник уже замінив параметри або нова пара вже записана в БД.
                        $journal->forget($connection->id);
                    } elseif ($record['phase'] === 'received') {
                        $recovered = $this->persistRotation($connection, $record['credentials'], $journal, $record['received_at']);
                        if ($recovered !== null) return $recovered;
                    } else {
                        $failure = new NovaPayException('auth_uncertain', 'Попереднє оновлення доступу NovaPay перервалося без збереженої відповіді. Повторну одноразову ротацію призупинено до перевірки підключення.');
                        $this->pauseAuthorization($connection, $failure);
                        throw $failure;
                    }
                }
                if ($connection->access_token && $connection->token_expires_at?->isFuture()) return $connection->access_token;
                $record = ['base' => $journal->fingerprint($connection), 'phase' => 'pending'];
                // Намір записуємо до HTTP: після аварії не використаємо стару пару повторно наосліп.
                $journal->write($connection->id, $record);
                try {
                    $result = $this->soap('UserAuthenticationJWT', [
                        'request_ref' => (string) Str::uuid(), 'refresh_token' => $connection->refresh_token,
                        'login' => $connection->login, 'public_certificate' => $connection->public_certificate,
                    ]);
                    $credentials = ['access_token' => Xml::value($result, 'jwt'),
                        'refresh_token' => Xml::value($result, 'refresh_token'),
                        'public_certificate' => Xml::value($result, 'public_certificate')];
                    if (in_array('', $credentials, true)) {
                        throw new NovaPayException('incomplete_response', 'NovaPay повернув неповну відповідь оновлення доступу.');
                    }
                } catch (NovaPayException $e) {
                    if ($e->reason === 'rate_limit') {
                        $journal->forget($connection->id);
                        throw $e;
                    }
                    $failure = $e->reason === 'authorization' ? $e : new NovaPayException('auth_'.$e->reason,
                        'Не вдалося оновити доступ NovaPay. '.$e->getMessage().' Оновлення призупинено до перевірки підключення; необхідність заміни токена не підтверджена.');
                    $this->pauseAuthorization($connection, $failure);
                    throw $failure;
                }
                $receivedAt = now()->getTimestamp();
                try {
                    $journal->write($connection->id, [...$record, 'phase' => 'received', 'credentials' => $credentials, 'received_at' => $receivedAt]);
                } catch (NovaPayException) {
                    // Якщо диск відмовив після відповіді, нижче ще намагаємося зберегти нову пару в БД.
                }
                $jwt = $this->persistRotation($connection, $credentials, $journal, $receivedAt);
                if ($jwt === null) {
                    throw new NovaPayException('auth_expired_response', 'Строк JWT NovaPay закінчився під час збереження. Нову пару доступу збережено; повторимо запит із нею.', true);
                }

                return $jwt;
            });
        } catch (LockTimeoutException) {
            throw new NovaPayException('busy', 'Авторизація NovaPay вже оновлюється. Повторимо запит пізніше.', true);
        }
    }

    private function persistRotation(NovaPayConnection $connection, array $credentials, AuthTokenJournal $journal, int $receivedAt): ?string
    {
        $expiry = $this->tokenExpiry($credentials['access_token'], $receivedAt);
        $usable = $expiry?->isFuture() ?? false;
        $failure = $expiry === null ? new NovaPayException('auth_invalid_expiry', 'NovaPay повернув JWT без коректного майбутнього строку дії. Нову пару доступу збережено, повторні ротації призупинено до перевірки відповіді провайдера.') : null;
        try {
            // Отриману пару зберігаємо навіть за некоректного exp; старий refresh уже може бути відкликаний.
            $saved = $connection->forceFill([...$credentials,
                'access_token' => $usable ? $credentials['access_token'] : null,
                'token_expires_at' => $usable ? $expiry : null, 'requires_auth' => $failure !== null,
                'auth_error_code' => $failure?->reason, 'auth_error_message' => $failure?->getMessage(),
            ])->save();
            if (! $saved) throw new \RuntimeException;
        } catch (\Throwable) {
            throw new NovaPayException('auth_persistence', 'Не вдалося записати отриману пару доступу NovaPay у базу. Наступна спроба перевірить захищений журнал; повторне використання невизначеної старої пари заблоковано.', true);
        }
        $journal->forget($connection->id);
        if ($failure) throw $failure;

        return $usable && $expiry->isFuture() ? $credentials['access_token'] : null;
    }

    private function throwPaused(NovaPayConnection $connection): never
    {
        throw new NovaPayException($connection->auth_error_code ?: 'authorization',
            $connection->auth_error_message ?: 'Оновлення доступу NovaPay призупинено. Перевірте підключення та історію синхронізації.');
    }

    private function pauseAuthorization(NovaPayConnection $connection, NovaPayException $failure): void
    {
        // Зберігаємо лише безпечну причину, щоб наступні задачі не замінили її загальним повідомленням.
        $connection->forceFill(['requires_auth' => true, 'access_token' => null, 'token_expires_at' => null,
            'auth_error_code' => $failure->reason, 'auth_error_message' => $failure->getMessage()])->save();
    }

    private function tokenExpiry(string $jwt, int $receivedAt): ?\Carbon\CarbonImmutable
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3 || ! preg_match('/^[A-Za-z0-9_-]+$/D', $parts[1])) return null;
        $decoded = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $payload = $decoded === false ? null : json_decode($decoded, true);
        $expiry = is_array($payload) ? ($payload['exp'] ?? null) : null;
        // Строк провайдера із запасом хвилина, без штучного обмеження двома годинами.
        if (! is_int($expiry) || $expiry <= $receivedAt + 60 || $expiry > 253402300799) return null;

        return \Carbon\CarbonImmutable::createFromTimestampUTC($expiry - 60)->setTimezone(config('app.timezone'));
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
