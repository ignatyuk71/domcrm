# Інструкція: відправка замовлень із сайту в CRM (DOM-CRM)

## Завдання
Коли на сайті оформлюється замовлення — автоматично надсилати його в нашу CRM одним HTTP-запитом (`POST`, JSON). CRM сама створить замовлення, знайде/створить клієнта і зіставить товари. Зворотного зв'язку не треба, тільки відправка **сайт → CRM**.

## 1. Доступи (видає власник CRM)
З розділу CRM «Налаштування → Інтеграції» власник дасть три значення:
- **URL:** `https://domcrm.com.ua/api/v1/orders/intake`
- **API-ключ** — у заголовок `X-Api-Key`
- **Секрет** — для підпису `X-Signature`

## 2. Запит
- Метод: `POST`
- Заголовки:
  - `Content-Type: application/json`
  - `Accept: application/json`
  - `X-Api-Key: <ключ>`
  - `X-Signature: <HMAC-SHA256 від тіла запиту, ключ = секрет, у hex>`

Підпис рахується від **точного тіла** запиту (того самого рядка JSON, що відправляється):
```php
$signature = hash_hmac('sha256', $body, $secret);
```

## 3. Тіло запиту (JSON)
```json
{
  "external_order_id": "1234",
  "customer": {
    "first_name": "Іван",
    "last_name": "Петренко",
    "phone": "0991234567",
    "email": "ivan@example.com"
  },
  "items": [
    {
      "external_id": "584",
      "sku": "6027-36-37",
      "name": "Тапочки вуличні сіро-блакитні",
      "size": "36/37",
      "qty": 1,
      "price": 530
    }
  ],
  "delivery": {
    "type": "warehouse",
    "city_name": "Київ",
    "warehouse_name": "Відділення №42",
    "recipient_name": "Іван Петренко",
    "recipient_phone": "0991234567"
  },
  "payment": { "method": "cod", "total": 530, "currency": "UAH" },
  "currency": "UAH",
  "note": "Подзвонити перед відправкою"
}
```

### Поля
| Поле | Тип | Обов'язкове | Опис |
|---|---|---|---|
| `external_order_id` | string | **так** | Номер замовлення на сайті. Має бути **стабільним** (за ним CRM відсікає дублі). |
| `customer.first_name` / `last_name` | string | бажано | Імʼя / прізвище. |
| `customer.phone` | string | **дуже бажано** | Телефон у будь-якому форматі — по ньому CRM знаходить/обʼєднує клієнта. |
| `customer.email` | string | ні | Email. |
| `items[].external_id` | string | бажано | ID товару на сайті. |
| `items[].sku` | string | **ключове** | **Варіантний SKU** (напр. `6027-36-37`) — той самий, що в CRM. По ньому товар розпізнається автоматично в потрібний розмір. |
| `items[].name` | string | **так** | Назва товару (зберігається як знімок). |
| `items[].size` | string | бажано | Розмір. |
| `items[].qty` | int | **так** | Кількість (≥ 1). |
| `items[].price` | number | **так** | Ціна за одиницю. |
| `delivery.type` | string | ні | `warehouse` (відділення), `courier` (адресна), `postomat`. За замовч. — відділення. |
| `delivery.city_name` | string | бажано | Місто **текстом**. |
| `delivery.warehouse_name` | string | бажано | Відділення/поштомат текстом. |
| `delivery.recipient_name` / `recipient_phone` | string | ні | Отримувач, якщо відрізняється від клієнта. |
| `payment.method` | string | ні | `cod` (накладений), `prepay`, `card`… За замовч. `cod`. |
| `payment.total` | number | ні | Сума замовлення. |
| `currency` | string | ні | За замовч. `UAH`. |
| `note` | string | ні | Коментар (іде у внутрішній коментар замовлення). |

## 4. Готовий приклад (PHP)
```php
function sendOrderToCrm(array $order): void
{
    $endpoint = 'https://domcrm.com.ua/api/v1/orders/intake';
    $apiKey   = 'ВСТАВИТИ_API_КЛЮЧ';
    $secret   = 'ВСТАВИТИ_СЕКРЕТ';

    $items = [];
    foreach ($order['items'] as $i) {
        $items[] = [
            'external_id' => (string) $i['product_id'],
            'sku'         => $i['sku'],          // варіантний SKU, як у CRM — головне!
            'name'        => $i['title'],
            'size'        => $i['size'] ?? '',
            'qty'         => (int) $i['qty'],
            'price'       => (float) $i['price'],
        ];
    }

    $payload = [
        'external_order_id' => (string) $order['id'],
        'customer' => [
            'first_name' => $order['first_name'] ?? '',
            'last_name'  => $order['last_name'] ?? '',
            'phone'      => $order['phone'] ?? '',
            'email'      => $order['email'] ?? '',
        ],
        'items'    => $items,
        'delivery' => [
            'type'           => 'warehouse',
            'city_name'      => $order['city'] ?? '',
            'warehouse_name' => $order['warehouse'] ?? '',
        ],
        'payment'  => ['method' => 'cod', 'total' => (float) $order['total'], 'currency' => 'UAH'],
        'currency' => 'UAH',
    ];

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Api-Key: '   . $apiKey,
            'X-Signature: ' . hash_hmac('sha256', $body, $secret),
        ],
    ]);
    $response = curl_exec($ch);
    $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Якщо не 2xx — зберегти й повторити пізніше (щоб не втратити замовлення)
    if ($code < 200 || $code >= 300) {
        file_put_contents(__DIR__ . '/crm_failed.log', date('c') . " HTTP {$code}: {$body}\n", FILE_APPEND);
    }
}

// Виклик одразу після збереження замовлення на сайті:
sendOrderToCrm($order);
```

## 5. Відповіді сервера
| Код | Значення |
|---|---|
| `202` | Прийнято й оброблено. Тіло: `{"accepted":true,"status":"processed","order_id":123,"needs_review":false}` |
| `200` | Дубль (цей `external_order_id` вже приймали) — повторно не створюється. |
| `401` | Немає/невірний `X-Api-Key` або невірний підпис `X-Signature`. |
| `403` | Інтеграцію вимкнено в CRM. |

## 6. Обов'язкові правила
1. **`external_order_id` стабільний** — не змінювати після створення (захист від дублів).
2. **`sku` = варіантний SKU, як у CRM** — головна умова авто-розпізнавання товару. Перелік SKU узгодити з власником один раз.
3. **Повтори при помилці** — якщо відповідь не 2xx або таймаут, поставити в чергу й повторити згодом. Дублів не буде (захищає `external_order_id`).
4. Тільки **HTTPS**.

## 7. Перевірка
Оформіть тестове замовлення на сайті → воно має з'явитися в CRM за кілька секунд. Власник підтвердить, що замовлення прийшло і товар розпізнався (або потрапив на ручне зіставлення, якщо SKU ще не узгоджені).
