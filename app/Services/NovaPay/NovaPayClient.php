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
        $result = $this->request($account->connection, 'GetAccountExtract', [
            'account_id' => (string) $account->provider_id,
            'date_from' => \Carbon\CarbonImmutable::parse($from)->format('d.m.Y'),
            'date_to' => \Carbon\CarbonImmutable::parse($to)->format('d.m.Y'),
        ]);

        return app(ExtractNormalizer::class)->normalize(Xml::value($result, 'extract'), $account, $from, $to);
    }

    private function request(NovaPayConnection $connection, string $method, array $params = []): DOMElement
    {
        try {
            return $this->soap($method, ['request_ref' => (string) Str::uuid(), 'jwt' => $this->token($connection), ...$params]);
        } catch (NovaPayException $e) {
            if ($e->reason === 'authorization') {
                $connection->update(['requires_auth' => true, 'access_token' => null, 'token_expires_at' => null]);
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
                    throw new NovaPayException('authorization', 'Потрібно відновити авторизацію NovaPay у налаштуваннях підключення.');
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
                        throw new NovaPayException('authorization', 'Неповна відповідь авторизації NovaPay. Відновіть параметри доступу.');
                    }
                    // Ротацію зберігаємо до інших запитів; старий refresh token може бути вже відкликаний.
                    $connection->forceFill([
                        'refresh_token' => $refresh, 'public_certificate' => $certificate,
                        'access_token' => $jwt, 'token_expires_at' => now()->addSeconds($this->tokenLifetime($jwt)),
                    ])->save();

                    return $jwt;
                } catch (NovaPayException $e) {
                    if ($e->reason !== 'rate_limit') {
                        // Після втрати відповіді неможливо безпечно повторити одноразову ротацію.
                        $connection->forceFill(['requires_auth' => true, 'access_token' => null, 'token_expires_at' => null])->save();
                        throw new NovaPayException('authorization', 'Авторизацію NovaPay не підтверджено. Згенеруйте нові параметри доступу в бізнес-кабінеті.');
                    }
                    throw $e;
                }
            });
        } catch (LockTimeoutException) {
            throw new NovaPayException('busy', 'Авторизація NovaPay вже оновлюється. Повторимо запит пізніше.', true);
        }
    }

    private function tokenLifetime(string $jwt): int
    {
        $parts = explode('.', $jwt);
        $payload = json_decode(base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true);
        $expiry = $payload['exp'] ?? null;

        return is_numeric($expiry) ? max(0, min(240, (int) $expiry - time() - 30)) : 0;
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
        } catch (ConnectionException) {
            throw new NovaPayException('network', 'NovaPay недоступний. Останні отримані дані збережено.', true);
        }
        if ($response->status() === 429) {
            throw new NovaPayException('rate_limit', 'Перевищено ліміт запитів NovaPay. Оновлення буде повторено пізніше.', true);
        }
        if (in_array($response->status(), [401, 403], true)) {
            throw new NovaPayException('authorization', 'NovaPay відхилив доступ. Перевірте авторизацію підключення.');
        }
        if (! $response->successful()) {
            throw new NovaPayException('provider_http', 'NovaPay не підтвердив запит. Останні отримані дані збережено.', $response->serverError());
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
