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
     * Загальний приватний метод для запитів до API Нової Пошти
     */
    private function makeRequest(string $model, string $method, array $properties = [])
    {
        if (!$this->apiKey) {
            Log::error("NovaPoshta: API key missing");
            return ['success' => false, 'errors' => ['API key is missing']];
        }

        try {
            $response = Http::timeout(15)->post($this->endpoint, [
                'apiKey' => $this->apiKey,
                'modelName' => $model,
                'calledMethod' => $method,
                'methodProperties' => $properties,
            ]);

            $result = $response->json();

            if (!$response->successful() || (isset($result['success']) && !$result['success'])) {
                Log::error("NovaPoshta API error: {$model}/{$method}", [
                    'errors' => $result['errors'] ?? $response->body(),
                    'sent_params' => $properties
                ]);
            }

            return $result;
        } catch (\Throwable $e) {
            Log::error("NovaPoshta exception: " . $e->getMessage());
            return ['success' => false, 'errors' => [$e->getMessage()]];
        }
    }

    /**
     * Створення ТТН на основі даних замовлення
     */
    public function createWaybill(\App\Models\Order $order)
    {
        $order->load(['delivery', 'customer', 'items.product', 'payment']);
        
        $delivery = $order->delivery;
        $customer = $order->customer;
        $payment  = $order->payment;
    
        // --- РОЗРАХУНОК ГАБАРИТІВ ТА ВАГИ ---
        $totalWeightKg = 0;
        $totalHeight = 0;
        $maxWidth = 0;
        $maxLength = 0;
    
        foreach ($order->items as $item) {
            $product = $item->product;
            $qty = $item->qty ?: 1;
    
            if ($product) {
                $totalWeightKg += ($product->weight_g / 1000) * $qty;
                $totalHeight += ($product->height_cm ?: 10) * $qty;
                $maxWidth = max($maxWidth, ($product->width_cm ?: 15));
                $maxLength = max($maxLength, ($product->length_cm ?: 25));
            } else {
                $totalWeightKg += 0.5 * $qty;
                $totalHeight += 10 * $qty;
                $maxWidth = max($maxWidth, 15);
                $maxLength = max($maxLength, 25);
            }
        }
    
        $totalWeightKg = $totalWeightKg ?: 0.5;
        $totalHeight = $totalHeight ?: 10;
        $maxWidth = $maxWidth ?: 15;
        $maxLength = $maxLength ?: 25;
        $volumetricVolume = ($maxWidth * $maxLength * $totalHeight) / 1000000;
    
        // --- РОЗРАХУНОК СУМ ---
        $calculatedTotal = $order->items->sum('total');
        $finalCost = ($calculatedTotal > 0) ? (string)round($calculatedTotal) : '500';
    
        $afterpayment = 0;
        if ($payment) {
            if (in_array($payment->method, ['cod', 'prepay'])) {
                $afterpayment = $calculatedTotal - ($payment->prepay_amount ?: 0);
            }
        }
        $afterpayment = ($afterpayment > 0) ? (string)round($afterpayment) : 0;
    
        // --- СТВОРЕННЯ КОНТРАГЕНТА (ОТРИМУВАЧА) ---
        $recipientPhone = preg_replace('/[^0-9]/', '', $customer->phone);
        
        $counterparty = $this->makeRequest('Counterparty', 'save', [
            'FirstName'            => trim($customer->first_name) ?: 'Клієнт',
            'LastName'             => trim($customer->last_name) ?: 'CRM',
            'Phone'                => $recipientPhone,
            'CounterpartyProperty' => 'Recipient',
            'CounterpartyType'     => 'PrivatePerson',
        ]);
    
        if (!($counterparty['success'] ?? false)) {
            return $counterparty;
        }
    
        $recipientRef = $counterparty['data'][0]['Ref'];
        $contactRef   = $counterparty['data'][0]['ContactPerson']['data'][0]['Ref'] ?? null;
    
        // --- ПІДГОТОВКА ПАРАМЕТРІВ ТТН ---
        $dbPayer = strtolower($delivery->delivery_payer ?? 'recipient');
        $payerType = ($dbPayer === 'sender') ? 'Sender' : 'Recipient';
    
        $params = [
            'Sender'             => config('services.nova_poshta.sender_ref'),
            'CitySender'         => config('services.nova_poshta.sender_city'),
            'SenderAddress'      => config('services.nova_poshta.sender_warehouse'),
            'ContactSender'      => config('services.nova_poshta.contact_ref'),
            'SendersPhone'       => config('services.nova_poshta.sender_phone'),
            
            'Recipient'          => $recipientRef,
            'ContactRecipient'   => $contactRef,
            'CityRecipient'      => $delivery->city_ref,
            'RecipientAddress'   => $delivery->warehouse_ref,
            'RecipientsPhone'    => $recipientPhone,
            
            'PayerType'          => $payerType, 
            'PaymentMethod'      => 'Cash',
            'DateTime'           => now()->format('d.m.Y'),
            'CargoType'          => 'Cargo',
            'ServiceType'        => $delivery->service_type ?? 'WarehouseWarehouse', 
            
            'Description'        => 'Взуття', 
            'Cost'               => $finalCost,
            'SeatsAmount'        => '1',
            'Weight'             => (string)$totalWeightKg,
            
            'OptionsSeat' => [
                [
                    'volumetricVolume' => (string)$volumetricVolume,
                    'volumetricWidth'  => (string)$maxWidth,
                    'volumetricLength' => (string)$maxLength,
                    'volumetricHeight' => (string)$totalHeight,
                    'weight'           => (string)$totalWeightKg,
                ]
            ],
    
            'AdditionalInformation' => (string)$order->id, 
            'InfoRegClientBarcodes' => 'Замовлення №' . $order->id,
        ];
    
        if ($afterpayment > 0) {
            $params['AfterpaymentOnGoodsCost'] = $afterpayment;
        }
    
        return $this->makeRequest('InternetDocument', 'save', $params);
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
                'ref'  => $item['DeliveryCity'] ?? $item['Ref'] ?? $item['SettlementRef'] ?? null,
                'name' => $item['Present'] ?? '',
                'area' => $item['Area'] ?? $item['Region'] ?? null,
            ];
        })->filter(fn($row) => !empty($row['ref']))->values()->all();
    }

    /**
     * Списки відділень
     */
    public function getWarehouses(string $cityRef, ?string $query = null, int $limit = 50): array
    {
        $resp = $this->makeRequest('AddressGeneral', 'getWarehouses', [
            'CityRef'      => $cityRef,
            'FindByString' => $query ?? '',
            'Page'         => 1,
            'Limit'        => $limit,
        ]);

        return collect($resp['data'] ?? [])->map(function ($item) {
            return [
                'ref'      => $item['Ref'] ?? null,
                'name'     => $item['Description'] ?? '',
                'category' => $item['Category'] ?? null,
                'type'     => $item['TypeOfWarehouse'] ?? null,
            ];
        })->values()->all();
    }

    /**
     * Отримання даних відправника (налаштовується один раз)
     */
    public function getSenderData(): array
    {
        $counterparties = $this->makeRequest('Counterparty', 'getCounterparties', [
            'CounterpartyProperty' => 'Sender',
            'Page' => 1
        ]);

        $sender = $counterparties['data'][0] ?? null;
        if (!$sender) return ['success' => false, 'message' => 'Sender not found'];

        $contacts = $this->makeRequest('Counterparty', 'getCounterpartyContactPersons', [
            'Ref' => $sender['Ref']
        ]);

        return [
            'success'         => true,
            'NP_SENDER_REF'   => $sender['Ref'],
            'NP_CONTACT_REF'  => $contacts['data'][0]['Ref'] ?? null,
            'NP_SENDER_PHONE' => $contacts['data'][0]['Phones'] ?? null,
        ];
    }

    /**
     * Видалення ТТН
     * @param string $ttnRef — внутрішній Ref накладної
     */
    public function deleteWaybill(string $ttnRef)
    {
        // ВИПРАВЛЕНО: метод API має назву 'delete'
        return $this->makeRequest('InternetDocument', 'delete', [
            'DocumentRefs' => [$ttnRef]
        ]);
    }
    /**
     * Посилання на друк МАЛЕНЬКОЇ наклейки (100х100)
     */
    public function getPrintLink(string $ttn): string
    {
        // Використовуємо printMarkings та zebra/1 для термопринтера
        return "https://my.novaposhta.ua/orders/printMarkings/orders[]/{$ttn}/type/pdf/zebra/1/apiKey/{$this->apiKey}";
    }
}