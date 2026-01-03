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
     * Пошук населених пунктів (autocomplete).
     */
    public function searchCities(string $query, int $limit = 20): array
    {
        if (!$this->apiKey) {
            Log::error('NovaPoshta: API key missing');
            return [];
        }

        try {
            $resp = Http::timeout(12)->post($this->endpoint, [
                'apiKey' => $this->apiKey,
                'modelName' => 'Address',
                'calledMethod' => 'searchSettlements',
                'methodProperties' => [
                    'CityName' => $query,
                    'Page' => 1,
                    'Limit' => $limit,
                ],
            ]);

            if (!$resp->successful()) {
                Log::error('NovaPoshta searchCities error', [
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                ]);
                return [];
            }

            $addresses = $resp->json('data.0.Addresses', []);
            return collect($addresses)->map(function ($item) {
                // searchSettlements повертає DeliveryCity (CityRef) та SettlementRef
                $ref = $item['DeliveryCity'] ?? $item['Ref'] ?? $item['SettlementRef'] ?? null;
                return [
                    'ref' => $ref,
                    'name' => $item['Present'] ?? $item['MainDescription'] ?? '',
                    'area' => $item['Area'] ?? $item['Region'] ?? null,
                ];
            })->filter(fn ($row) => !empty($row['ref']))->values()->all();
        } catch (\Throwable $e) {
            Log::error('NovaPoshta searchCities exception', ['message' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Відділення/поштомати за містом.
     */
    public function getWarehouses(string $cityRef, ?string $query = null, int $limit = 50): array
    {
        if (!$this->apiKey) {
            Log::error('NovaPoshta: API key missing');
            return [];
        }

        try {
            $resp = Http::timeout(12)->post($this->endpoint, [
                'apiKey' => $this->apiKey,
                'modelName' => 'AddressGeneral',
                'calledMethod' => 'getWarehouses',
                'methodProperties' => [
                    'CityRef' => $cityRef,
                    'FindByString' => $query ?? '',
                    'Page' => 1,
                    'Limit' => $limit,
                ],
            ]);

            if (!$resp->successful()) {
                Log::error('NovaPoshta getWarehouses error', [
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                ]);
                return [];
            }

            return collect($resp->json('data', []))->map(function ($item) {
                return [
                    'ref' => $item['Ref'] ?? null,
                    'name' => $item['Description'] ?? '',
                    'number' => $item['Number'] ?? null,
                    'type' => $item['TypeOfWarehouse'] ?? null,
                    'short_address' => $item['ShortAddress'] ?? null,
                ];
            })->values()->all();
        } catch (\Throwable $e) {
            Log::error('NovaPoshta getWarehouses exception', ['message' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Пошук вулиць за містом.
     */
    public function searchStreets(string $cityRef, string $query, int $limit = 30): array
    {
        if (!$this->apiKey || !$cityRef) {
            return [];
        }

        try {
            $resp = Http::timeout(12)->post($this->endpoint, [
                'apiKey' => $this->apiKey,
                'modelName' => 'Address',
                'calledMethod' => 'getStreet',
                'methodProperties' => [
                    'CityRef' => $cityRef,
                    'FindByString' => $query,
                    'Limit' => $limit,
                ],
            ]);

            if (!$resp->successful()) {
                Log::error('NovaPoshta searchStreets error', [
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                ]);
                return [];
            }

            return collect($resp->json('data', []))->map(function ($item) {
                return [
                    'ref' => $item['Ref'] ?? $item['StreetRef'] ?? null,
                    'name' => $item['Description'] ?? '',
                ];
            })->values()->all();
        } catch (\Throwable $e) {
            Log::error('NovaPoshta searchStreets exception', ['message' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Одне відділення по Ref.
     */
    public function getWarehouseByRef(string $ref): ?array
    {
        if (!$this->apiKey) {
            Log::error('NovaPoshta: API key missing');
            return null;
        }

        try {
            $resp = Http::timeout(12)->post($this->endpoint, [
                'apiKey' => $this->apiKey,
                'modelName' => 'AddressGeneral',
                'calledMethod' => 'getWarehouses',
                'methodProperties' => [
                    'Ref' => $ref,
                    'Page' => 1,
                    'Limit' => 1,
                ],
            ]);

            if (!$resp->successful()) {
                Log::error('NovaPoshta getWarehouseByRef error', [
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                ]);
                return null;
            }

            $row = $resp->json('data.0');
            if (!$row) {
                return null;
            }

            return [
                'ref' => $row['Ref'] ?? null,
                'name' => $row['Description'] ?? '',
                'short_address' => $row['ShortAddress'] ?? null,
                'type' => $row['TypeOfWarehouse'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::error('NovaPoshta getWarehouseByRef exception', ['message' => $e->getMessage()]);
            return null;
        }
    }
}
