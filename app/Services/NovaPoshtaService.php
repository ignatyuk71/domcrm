<?php

namespace App\Services;

use App\Models\NovaPoshtaSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NovaPoshtaService
{
    protected string $apiKey;
    protected string $endpoint = 'https://api.novaposhta.ua/v2.0/json/';
    protected ?NovaPoshtaSetting $settings = null;

    public function __construct()
    {
        $this->settings = NovaPoshtaSetting::current();
        $this->apiKey = $this->settings?->api_key
            ?? config('services.nova_poshta.api_key')
            ?? config('services.novaposhta.key')
            ?? env('NOVA_POSHTA_API_KEY', '');
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    private function setting(string $field, string $configKey): ?string
    {
        return $this->settings?->{$field} ?: config($configKey);
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
            $response = Http::timeout(50)->post($this->endpoint, [
                'apiKey' => $this->apiKey,
                'modelName' => $model,
                'calledMethod' => $method,
                'methodProperties' => $properties,
            ]);

            $result = $response->json();

            // Фільтруємо помилки, які ми плануємо обробляти автоматично (щоб не засмічувати логи)
            $isHandledError = false;
            if (isset($result['errors']) && is_array($result['errors'])) {
                $errStr = implode(' ', $result['errors']);
                // Ці помилки означають, що треба спробувати інший CityRef, тому це не критичний збій
                if (str_contains($errStr, "Street doesn't exists") || str_contains($errStr, "Recipient address is not in this city")) { 
                    $isHandledError = true;
                }
            }

            if ((!$response->successful() || (isset($result['success']) && !$result['success'])) && !$isHandledError) {
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

    private function makeRequestWithKey(string $apiKey, string $model, string $method, array $properties = []): array
    {
        if (!$apiKey) {
            return ['success' => false, 'errors' => ['API key is missing']];
        }

        try {
            $response = Http::timeout(15)->post($this->endpoint, [
                'apiKey' => $apiKey,
                'modelName' => $model,
                'calledMethod' => $method,
                'methodProperties' => $properties,
            ]);

            return $response->json();
        } catch (\Throwable $e) {
            return ['success' => false, 'errors' => [$e->getMessage()]];
        }
    }

    public function fetchRefsByApiKey(string $apiKey): array
    {
        $counterparties = $this->makeRequestWithKey($apiKey, 'Counterparty', 'getCounterparties', [
            'CounterpartyProperty' => 'Sender',
            'Page' => 1,
        ]);

        if (!($counterparties['success'] ?? false)) {
            return [
                'success' => false,
                'errors' => $counterparties['errors'] ?? null,
                'message' => 'Не вдалося отримати відправника',
            ];
        }

        $sender = $counterparties['data'][0] ?? null;
        if (!$sender) {
            return [
                'success' => false,
                'errors' => ['Sender not found'],
                'message' => 'Не вдалося отримати відправника',
            ];
        }

        $contacts = $this->makeRequestWithKey($apiKey, 'Counterparty', 'getCounterpartyContactPersons', [
            'Ref' => $sender['Ref'],
        ]);

        if (!($contacts['success'] ?? false)) {
            return [
                'success' => false,
                'errors' => $contacts['errors'] ?? null,
                'message' => 'Не вдалося отримати контактну особу',
            ];
        }

        $contact = $contacts['data'][0] ?? null;

        return [
            'success' => true,
            'data' => [
                'sender_ref' => $sender['Ref'] ?? null,
                'contact_ref' => $contact['Ref'] ?? null,
                'sender_phone' => $contact['Phones'] ?? null,
            ],
        ];
    }

    /**
     * Створення ТТН на основі даних замовлення (для відділення)
     */
    public function createWaybill(\App\Models\Order $order)
    {
        $order->load('delivery');
        if (($order->delivery->delivery_type ?? 'warehouse') === 'courier') {
            return $this->createWaybillCourier($order);
        }

        $order->load(['delivery', 'customer', 'items.product', 'payment']);
        
        $delivery = $order->delivery;
        $customer = $order->customer;
        $payment  = $order->payment;
        $itemsSummary = $this->buildItemsSummary($order);
    
        // 1. Розрахунок ваги/об'єму (спрощено винесено в окремий блок для читабельності, але логіка та ж)
        $dimensions = $this->calculateDimensions($order->items);
    
        // 2. Розрахунок вартості
        $costs = $this->calculateCosts($order, $payment);
    
        // 3. Контрагент
        $recipientData = $this->getOrCreateRecipient($customer);
        if (!$recipientData['success']) return $recipientData;
        
        $recipientRef = $recipientData['ref'];
        $contactRef = $recipientData['contact_ref'];
        $recipientPhone = $recipientData['phone'];
    
        // 4. Параметри ТТН
        $recipientAddressRef = $delivery->warehouse_ref;
        $payerType = (strtolower($delivery->delivery_payer ?? 'recipient') === 'sender') ? 'Sender' : 'Recipient';

        $params = [
            'Sender'             => $this->setting('sender_ref', 'services.nova_poshta.sender_ref'),
            'CitySender'         => $this->setting('sender_city_ref', 'services.nova_poshta.sender_city'),
            'SenderAddress'      => $this->setting('sender_warehouse_ref', 'services.nova_poshta.sender_warehouse'),
            'ContactSender'      => $this->setting('contact_ref', 'services.nova_poshta.contact_ref'),
            'SendersPhone'       => $this->setting('sender_phone', 'services.nova_poshta.sender_phone'),
            
            'Recipient'          => $recipientRef,
            'ContactRecipient'   => $contactRef,
            'CityRecipient'      => $delivery->city_ref,
            'RecipientAddress'   => $recipientAddressRef,
            'RecipientsPhone'    => $recipientPhone,
            
            'PayerType'          => $payerType, 
            'PaymentMethod'      => 'Cash',
            'CargoType'          => 'Cargo',
            'ServiceType'        => \App\Models\OrderDelivery::SERVICE_WAREHOUSE, 
            'Description'        => 'Взуття', 
            'Cost'               => $costs['cost'],
            'SeatsAmount'        => '1',
            'Weight'             => $dimensions['weight'],
            'OptionsSeat'        => [$dimensions['options']],
            'AdditionalInformation' => $itemsSummary ?: ('Зам. №' . $order->id),
            'InfoRegClientBarcodes' => 'Зам. №' . $order->id,
        ];
    
        if ($costs['afterpayment'] > 0) {
            $params['AfterpaymentOnGoodsCost'] = $costs['afterpayment'];
        }
    
        return $this->makeRequest('InternetDocument', 'save', $params);
    }


    public function createWaybillCourier(\App\Models\Order $order): array
    {
        $order->load(['delivery', 'customer', 'items.product', 'payment']);

        $delivery = $order->delivery;
        $customer = $order->customer;
        $payment  = $order->payment;
        $itemsSummary = $this->buildItemsSummary($order);

        $dimensions = $this->calculateDimensions($order->items);
        $costs = $this->calculateCosts($order, $payment);

        $phone = preg_replace('/[^0-9]/', '', (string) $customer->phone);

        $settlementRef = $delivery->settlement_ref ?: $delivery->city_ref;
        $street = trim((string) $delivery->street_name);
        $house  = trim((string) $delivery->building);
        $flat   = trim((string) $delivery->apartment);

        $recipientName = trim((string)($customer->last_name . ' ' . $customer->first_name));
        $recipientName = $recipientName ?: 'Отримувач';

        $payerType = (strtolower($delivery->delivery_payer ?? 'recipient') === 'sender') ? 'Sender' : 'Recipient';

        $params = [
            // sender
            'Sender'        => $this->setting('sender_ref', 'services.nova_poshta.sender_ref'),
            'CitySender'    => $this->setting('sender_city_ref', 'services.nova_poshta.sender_city'),
            'SenderAddress' => $this->setting('sender_warehouse_ref', 'services.nova_poshta.sender_warehouse'),
            'ContactSender' => $this->setting('contact_ref', 'services.nova_poshta.contact_ref'),
            'SendersPhone'  => $this->setting('sender_phone', 'services.nova_poshta.sender_phone'),

            // recipient (СХЕМА NEW ADDRESS)
            'RecipientCityRef'      => $settlementRef,
            'RecipientAddressName'  => $street,
            'RecipientHouse'        => $house,
            'RecipientFlat'         => $flat,

            'RecipientType'         => 'PrivatePerson',
            'RecipientName'         => $recipientName,
            'RecipientContactName'  => $recipientName,
            'RecipientsPhone'       => $phone,

            'NewAddress'            => '1',

            // shipment
            'ServiceType'   => \App\Models\OrderDelivery::SERVICE_DOORS,
            'CargoType'     => 'Cargo',
            'PayerType'     => $payerType,
            'PaymentMethod' => 'Cash',

            'SeatsAmount'   => '1',
            'Weight'        => $dimensions['weight'],
            'OptionsSeat'   => [$dimensions['options']],
            'Cost'          => $costs['cost'],
            'Description'   => 'Взуття',
            'AdditionalInformation' => $itemsSummary ?: ('Зам. №' . $order->id),
            'InfoRegClientBarcodes' => 'Зам. №' . $order->id,
        ];

        if ($costs['afterpayment'] > 0) {
            $params['AfterpaymentOnGoodsCost'] = $costs['afterpayment'];
        }

        $resp = $this->makeRequest('InternetDocument', 'save', $params);
        if (!($resp['success'] ?? false)) {
            \Log::error('NP InternetDocument/save failed', [
                'order_id' => $order->id,
                'sent' => $params,
                'resp' => $resp,
            ]);
        }

        return $resp;
    }
    
    
    

    // --- ДОПОМІЖНІ МЕТОДИ (винесені для чистоти коду) ---

    private function calculateDimensions($items): array
    {
        $totalWeightKg = 0;
        $totalVolumeCm3 = 0;
        $maxItemWidth = 0; $maxItemLength = 0; $maxItemHeight = 0;

        foreach ($items as $item) {
            $product = $item->product;
            $qty = max($item->qty ?: 1, 1);
            $w = $product?->width_cm ?: 20;
            $l = $product?->length_cm ?: 30;
            $h = $product?->height_cm ?: 10;
            $weight = $product ? ($product->weight_g / 1000) : 0.5;

            $totalWeightKg += $weight * $qty;
            $totalVolumeCm3 += ($w * $l * $h) * $qty;
            $maxItemWidth = max($maxItemWidth, $w);
            $maxItemLength = max($maxItemLength, $l);
            $maxItemHeight = max($maxItemHeight, $h);
        }

        $totalWeightKg = max($totalWeightKg, 0.1);
        $calcLength = max($maxItemLength, 10);
        $calcWidth = max($maxItemWidth, 10);
        $calcHeight = ceil(($totalVolumeCm3 * 1.1) / ($calcLength * $calcWidth));
        
        // Проста логіка пакування (якщо дуже висока, розширюємо основу)
        if ($calcHeight > 60) { $calcWidth *= 2; $calcHeight = ceil(($totalVolumeCm3 * 1.1) / ($calcLength * $calcWidth)); }
        if ($calcHeight > 60) { $calcLength *= 2; $calcHeight = ceil(($totalVolumeCm3 * 1.1) / ($calcLength * $calcWidth)); }
        $calcHeight = max($calcHeight, 1);

        $volumetricVolume = ($calcLength * $calcWidth * $calcHeight) / 1000000;

        return [
            'weight' => (string)$totalWeightKg,
            'options' => [
                'volumetricVolume' => (string)$volumetricVolume,
                'volumetricWidth'  => (string)$calcWidth,
                'volumetricLength' => (string)$calcLength,
                'volumetricHeight' => (string)$calcHeight,
                'weight'           => (string)$totalWeightKg,
            ]
        ];
    }

    private function calculateCosts($order, $payment): array
    {
        $calculatedTotal = $order->items->sum('total');
        $finalCost = ($calculatedTotal > 0) ? (string)round($calculatedTotal) : '200';
        
        $afterpayment = 0;
        if ($payment && in_array($payment->method, ['cod', 'prepay'])) {
            $afterpayment = $calculatedTotal - ($payment->prepay_amount ?: 0);
        }
        $afterpayment = ($afterpayment > 0) ? (string)round($afterpayment) : 0;

        return ['cost' => $finalCost, 'afterpayment' => $afterpayment];
    }

    private function getOrCreateRecipient($customer): array
    {
        $recipientPhone = preg_replace('/[^0-9]/', '', $customer->phone);
        
        $counterparty = $this->makeRequest('Counterparty', 'save', [
            'FirstName'            => trim($customer->first_name) ?: 'Клієнт',
            'LastName'             => trim($customer->last_name) ?: 'CRM',
            'Phone'                => $recipientPhone,
            'CounterpartyProperty' => 'Recipient',
            'CounterpartyType'     => 'PrivatePerson',
        ]);
    
        if (!($counterparty['success'] ?? false)) {
            return ['success' => false, 'errors' => $counterparty['errors'] ?? ['Error creating counterparty']];
        }

        return [
            'success' => true,
            'ref' => $counterparty['data'][0]['Ref'],
            'contact_ref' => $counterparty['data'][0]['ContactPerson']['data'][0]['Ref'] ?? null,
            'phone' => $recipientPhone
        ];
    }

    private function buildItemsSummary(\App\Models\Order $order): string
    {
        $parts = [];
        foreach ($order->items as $item) {
            $labelParts = array_values(array_filter([
                trim((string)($item->product?->category?->name ?? '')),
                trim((string)($item->product?->color?->name ?? '')),
                trim((string)($item->size ?? ''))
            ]));
            if (!$labelParts) continue;
            $parts[] = implode('/', $labelParts) . ' - ' . max((int)$item->qty, 1).'пар.';
        }
        $summary = trim(implode('; ', $parts));
        return mb_strlen($summary) > 100 ? mb_substr($summary, 0, 97) . '...' : $summary;
    }

    // --- МЕТОДИ ПОШУКУ (без змін) ---
    
    public function searchCities(string $query, int $limit = 20): array
    {
        $resp = $this->makeRequest('Address', 'searchSettlements', ['CityName' => $query, 'Page' => 1, 'Limit' => $limit]);
        return collect($resp['data'][0]['Addresses'] ?? [])->map(fn($item) => [
            'ref' => $item['DeliveryCity'] ?? $item['Ref'] ?? $item['SettlementRef'] ?? null,
            'delivery_city_ref' => $item['DeliveryCity'] ?? null,
            'settlement_ref' => $item['SettlementRef'] ?? $item['Ref'] ?? null,
            'name' => $item['Present'] ?? '',
            'area' => $item['Area'] ?? $item['Region'] ?? null,
            'type' => $item['SettlementTypeDescription'] ?? null,
        ])->filter(fn($row) => !empty($row['ref']))->values()->all();
    }

    public function getWarehouses(string $cityRef, ?string $query = null, int $limit = 50): array
    {
        $resp = $this->makeRequest('AddressGeneral', 'getWarehouses', ['CityRef' => $cityRef, 'FindByString' => $query ?? '', 'Page' => 1, 'Limit' => $limit]);
        return collect($resp['data'] ?? [])->map(fn($item) => [
            'ref' => $item['Ref'] ?? null,
            'name' => $item['Description'] ?? '',
            'category' => $item['Category'] ?? null,
            'type' => $item['TypeOfWarehouse'] ?? null,
        ])->values()->all();
    }

    public function searchSettlementStreets(string $settlementRef, string $query, int $limit = 25): array
    {
        if (mb_strlen(trim($query)) < 2) return [];
        $resp = $this->makeRequest('Address', 'searchSettlementStreets', ['SettlementRef' => $settlementRef, 'StreetName' => trim($query), 'Limit' => $limit]);
        return collect($resp['data'][0]['Addresses'] ?? [])->map(fn($item) => [
            'ref' => $item['SettlementStreetRef'] ?? $item['StreetRef'] ?? null,
            'name' => $item['Present'] ?? $item['Description'] ?? '',
            'type' => $item['StreetsType'] ?? null,
        ])->filter(fn($row) => !empty($row['ref']))->values()->all();
    }

    public function searchStreets(string $cityRef, string $query, int $limit = 25): array
    {
        if (mb_strlen(trim($query)) < 2 || !$cityRef) return [];
        $resp = $this->makeRequest('AddressGeneral', 'getStreet', ['CityRef' => $cityRef, 'FindByString' => trim($query), 'Page' => 1, 'Limit' => $limit]);
        return collect($resp['data'] ?? [])->map(fn($item) => [
            'ref' => $item['Ref'] ?? null,
            'name' => $item['Description'] ?? '',
        ])->filter(fn($row) => !empty($row['ref']))->values()->all();
    }
    
    public function deleteWaybill(string $ttnRef)
    {
        return $this->makeRequest('InternetDocument', 'delete', ['DocumentRefs' => [$ttnRef]]);
    }

    public function getPrintLink(string $ttn): string
    {
        return "https://my.novaposhta.ua/orders/printMarkings/orders[]/{$ttn}/type/pdf/zebra/1/apiKey/{$this->apiKey}";
    }

    public function getStatuses(array $documents): ?array
    {
        if (empty($documents)) {
            return null;
        }

        // Підтримуємо різні формати вхідних ключів (cron, manual, legacy).
        $payload = [
            'Documents' => array_map(function (array $doc) {
                return [
                    'DocumentNumber' => $doc['DocumentNumber'] ?? $doc['ttn'] ?? $doc['number'] ?? null,
                    'Phone' => $doc['Phone'] ?? $doc['phone'] ?? null,
                ];
            }, $documents),
        ];
        return $this->makeRequest('TrackingDocument', 'getStatusDocuments', $payload);
    }
}
