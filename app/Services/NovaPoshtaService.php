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
     * ОНОВЛЕНО: Розумний розрахунок габаритів (об'єм замість висоти)
     */
    public function createWaybill(\App\Models\Order $order)
    {
        $order->load(['delivery', 'customer', 'items.product', 'payment']);
        
        $delivery = $order->delivery;
        $customer = $order->customer;
        $payment  = $order->payment;
        $itemsSummary = $this->buildItemsSummary($order);
    
        // --- 1. РОЗРАХУНОК ВАГИ ТА ОБ'ЄМУ ---
        $totalWeightKg = 0;
        $totalVolumeCm3 = 0;
        
        // Максимальні габарити ОДНОГО товару (щоб коробка не була меншою за товар)
        $maxItemWidth = 0;
        $maxItemLength = 0;
        $maxItemHeight = 0;
    
        foreach ($order->items as $item) {
            $product = $item->product;
            $qty = $item->qty ?: 1;
            
            // Дефолтні розміри (якщо у товарі 0)
            $w = $product?->width_cm ?: 20;
            $l = $product?->length_cm ?: 30;
            $h = $product?->height_cm ?: 10; // змінив дефолт на 10, щоб було компактніше
            $weight = $product ? ($product->weight_g / 1000) : 0.5;

            // Накопичуємо загальну вагу та загальний об'єм
            $totalWeightKg += $weight * $qty;
            $totalVolumeCm3 += ($w * $l * $h) * $qty;

            // Запам'ятовуємо найбільший товар
            $maxItemWidth = max($maxItemWidth, $w);
            $maxItemLength = max($maxItemLength, $l);
            $maxItemHeight = max($maxItemHeight, $h);
        }
    
        // Мінімальна вага 0.1 кг
        $totalWeightKg = max($totalWeightKg, 0.1);

        // --- 2. ПІДБІР ГАБАРИТІВ КОРОБКИ ---
        // Починаємо з бази: довжина і ширина найбільшого товару
        $calcLength = max($maxItemLength, 10);
        $calcWidth = max($maxItemWidth, 10);
        
        // Вираховуємо висоту з об'єму: H = V / (L * W) + 10% запасу
        $calcHeight = ceil(($totalVolumeCm3 * 1.1) / ($calcLength * $calcWidth));

        // ЛОГІКА "СПЛЮЩУВАННЯ": 
        // Якщо висота > 60 см, пробуємо збільшити ширину (покласти товари поруч)
        if ($calcHeight > 60) {
            $calcWidth *= 2; 
            $calcHeight = ceil(($totalVolumeCm3 * 1.1) / ($calcLength * $calcWidth));
        }

        // Якщо все ще високо, збільшуємо довжину
        if ($calcHeight > 60) {
            $calcLength *= 2;
            $calcHeight = ceil(($totalVolumeCm3 * 1.1) / ($calcLength * $calcWidth));
        }
        
        // Фінальна страховка (мінімум 1 см висоти)
        $calcHeight = max($calcHeight, 1);

        // Розрахунок об'ємної ваги для API
        $volumetricVolume = ($calcLength * $calcWidth * $calcHeight) / 1000000;
    
        // --- 3. РОЗРАХУНОК СУМ ---
        $calculatedTotal = $order->items->sum('total');
        // Оціночна вартість (мінімум 200 грн, щоб не було помилок, якщо сума 0)
        $finalCost = ($calculatedTotal > 0) ? (string)round($calculatedTotal) : '200';
    
        $afterpayment = 0;
        if ($payment) {
            if (in_array($payment->method, ['cod', 'prepay'])) {
                $afterpayment = $calculatedTotal - ($payment->prepay_amount ?: 0);
            }
        }
        $afterpayment = ($afterpayment > 0) ? (string)round($afterpayment) : 0;
    
        // --- 4. КОНТРАГЕНТ (ОТРИМУВАЧ) ---
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
    
        // --- 5. ПАРАМЕТРИ ТТН ---
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
            
            'CargoType'          => 'Cargo',
            'ServiceType'        => $delivery->service_type ?? 'WarehouseWarehouse', 
            
            'Description'        => 'Взуття', 
            'Cost'               => $finalCost,
            'SeatsAmount'        => '1',
            'Weight'             => (string)$totalWeightKg,
            
            // Передаємо розраховані габарити "віртуальної коробки"
            'OptionsSeat' => [
                [
                    'volumetricVolume' => (string)$volumetricVolume,
                    'volumetricWidth'  => (string)$calcWidth,
                    'volumetricLength' => (string)$calcLength,
                    'volumetricHeight' => (string)$calcHeight,
                    'weight'           => (string)$totalWeightKg,
                ]
            ],

            'AdditionalInformation' => $itemsSummary ?: ('Замовлення №' . $order->id),
            'InfoRegClientBarcodes' => 'Замовлення №' . $order->id,
        ];
    
        if ($afterpayment > 0) {
            $params['AfterpaymentOnGoodsCost'] = $afterpayment;
        }
    
        return $this->makeRequest('InternetDocument', 'save', $params);
    }

    private function buildItemsSummary(\App\Models\Order $order): string
    {
        $parts = [];
        foreach ($order->items as $item) {
            $desc = trim((string) ($item->product?->description ?? ''));
            if ($desc === '') {
                continue;
            }
            $qty = (int) ($item->qty ?: 1);
            $parts[] = $desc . ' ' . ($qty > 1 ? "- {$qty} пари" : '- 1 пара');
        }

        $summary = trim(preg_replace('/\s+/', ' ', implode('; ', $parts)));
        if ($summary === '') {
            return '';
        }

        $limit = 100;
        $length = function_exists('mb_strlen') ? mb_strlen($summary) : strlen($summary);
        if ($length > $limit) {
            $cut = $limit - 3;
            $summary = function_exists('mb_substr')
                ? mb_substr($summary, 0, $cut)
                : substr($summary, 0, $cut);
            $summary .= '...';
        }

        return $summary;
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
        return $this->makeRequest('InternetDocument', 'delete', [
            'DocumentRefs' => [$ttnRef]
        ]);
    }
    
    /**
     * Посилання на друк МАЛЕНЬКОЇ наклейки (100х100)
     */
    public function getPrintLink(string $ttn): string
    {
        return "https://my.novaposhta.ua/orders/printMarkings/orders[]/{$ttn}/type/pdf/zebra/1/apiKey/{$this->apiKey}";
    }

    /**
     * Отримання статусів відправлень (масовий трекінг).
     */
    public function getStatuses(array $documents): ?array
    {
        if (empty($documents)) {
            return null;
        }

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
