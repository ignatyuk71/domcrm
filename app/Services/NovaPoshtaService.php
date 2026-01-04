<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NovaPoshtaService
{
    protected string $apiKey;
    protected string $endpoint = 'https://api.novaposhta.ua/v2.0/json/';

    public function __construct()
    {
        $this->apiKey = config('services.nova_poshta.api_key')
            ?? config('services.novaposhta.key')
            ?? env('NOVA_POSHTA_API_KEY', '');
    }

    /**
     * Загальний метод для виконання запитів
     */
    private function makeRequest(string $model, string $method, array $properties = [])
    {
        if (!$this->apiKey) {
            Log::error("NovaPoshta: API key missing");
            return null;
        }

        try {
            $response = Http::timeout(12)->post($this->endpoint, [
                'apiKey' => $this->apiKey,
                'modelName' => $model,
                'calledMethod' => $method,
                'methodProperties' => $properties,
            ]);

            if (!$response->successful()) {
                Log::error("NovaPoshta API error: {$model}/{$method}", [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            Log::error("NovaPoshta exception: {$model}/{$method}", ['message' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Пошук міст
     */
    public function searchCities(string $query, int $limit = 20): array
    {
        $resp = $this->makeRequest('Address', 'searchSettlements', [
            'CityName' => $query,
            'Page' => 1,
            'Limit' => $limit,
        ]);

        $addresses = $resp['data'][0]['Addresses'] ?? [];

        return collect($addresses)->map(function ($item) {
            return [
                'ref' => $item['DeliveryCity'] ?? $item['Ref'] ?? $item['SettlementRef'] ?? null,
                'name' => $item['Present'] ?? '',
                'area' => $item['Area'] ?? $item['Region'] ?? null,
            ];
        })->filter(fn($row) => !empty($row['ref']))->values()->all();
    }

    /**
     * Відділення/поштомати
     */
    public function getWarehouses(string $cityRef, ?string $query = null, int $limit = 50): array
    {
        $resp = $this->makeRequest('AddressGeneral', 'getWarehouses', [
            'CityRef' => $cityRef,
            'FindByString' => $query ?? '',
            'Page' => 1,
            'Limit' => $limit,
        ]);

        return collect($resp['data'] ?? [])->map(function ($item) {
            return [
                'ref' => $item['Ref'] ?? null,
                'name' => $item['Description'] ?? '',
                'number' => $item['Number'] ?? null,
                'type' => $item['TypeOfWarehouse'] ?? null,
                'category' => $item['Category'] ?? null,
                'short_address' => $item['ShortAddress'] ?? null,
            ];
        })->values()->all();
    }

    /**
     * Пошук вулиць
     */
    public function searchStreets(string $cityRef, string $query, int $limit = 30): array
    {
        $resp = $this->makeRequest('Address', 'getStreet', [
            'CityRef' => $cityRef,
            'FindByString' => $query,
            'Limit' => $limit,
        ]);

        return collect($resp['data'] ?? [])->map(function ($item) {
            return [
                'ref' => $item['Ref'] ?? null,
                'name' => $item['Description'] ?? '',
            ];
        })->values()->all();
    }

    /**
     * Одне відділення по Ref
     */
    public function getWarehouseByRef(string $ref): ?array
    {
        $resp = $this->makeRequest('AddressGeneral', 'getWarehouses', [
            'Ref' => $ref,
            'Page' => 1,
            'Limit' => 1,
        ]);

        $row = $resp['data'][0] ?? null;
        if (!$row) return null;

        return [
            'ref' => $row['Ref'] ?? null,
            'name' => $row['Description'] ?? '',
            'short_address' => $row['ShortAddress'] ?? null,
            'category' => $row['Category'] ?? null,
            'type' => $row['TypeOfWarehouse'] ?? null,
        ];
    }

    /**
     * ОТРИМАННЯ ДАНИХ ВІДПРАВНИКА ДЛЯ .ENV
     * Викликайте цей метод один раз, щоб отримати UUID для налаштувань
     */
    public function getSenderData(): array
    {
        // 1. Отримуємо Контрагента (Ваш ФОП або Компанія)
        $counterparties = $this->makeRequest('Counterparty', 'getCounterparties', [
            'CounterpartyProperty' => 'Sender',
            'Page' => 1
        ]);

        $sender = $counterparties['data'][0] ?? null;

        if (!$sender) {
            return ['error' => 'Відправника не знайдено. Перевірте API ключ.'];
        }

        // 2. Отримуємо Контактну особу
        $contacts = $this->makeRequest('Counterparty', 'getCounterpartyContactPersons', [
            'Ref' => $sender['Ref'],
            'Page' => 1
        ]);

        $contact = $contacts['data'][0] ?? null;

        return [
            'INFO' => 'Скопіюйте ці значення у ваш .env файл',
            'DATA' => [
                'NP_SENDER_REF'    => $sender['Ref'],
                'NP_SENDER_NAME'   => $sender['Description'],
                'NP_CONTACT_REF'   => $contact['Ref'] ?? 'Не знайдено',
                'NP_CONTACT_NAME'  => $contact['Description'] ?? 'Не знайдено',
                'NP_SENDER_PHONE'  => $contact['Phones'] ?? 'Не знайдено',
            ]
        ];
    }
}