# Формат відправки замовлень у DOM-CRM (для розробника сайту)

Цей документ — контракт для **PUSH**-інтеграції (власний PHP-сайт, Shopify, OpenCart). Сайт надсилає замовлення в CRM HTTP-запитом. Для Prom (PULL) формат не потрібен — CRM тягне сама.

## 1. Куди надсилати

```
POST  {BASE_URL}/api/v1/orders/intake
```

- Локально (dev): `http://127.0.0.1:8000/api/v1/orders/intake`
- Продакшн: `https://domcrm.com.ua/api/v1/orders/intake`

> Точну адресу, **API-ключ** і **секрет** бери в CRM: **Налаштування → Інтеграції** → твоє підключення.

## 2. Заголовки

| Заголовок | Обовʼязковий | Значення |
|---|---|---|
| `Content-Type` | так | `application/json` |
| `Accept` | так | `application/json` |
| `X-Api-Key` | так | API-ключ із картки інтеграції |
| `X-Signature` | якщо заданий секрет | HMAC-SHA256 (hex) від **сирого тіла** запиту, ключ = секрет |

Підпис рахується від **точного тіла** (того самого рядка JSON, який відправляєш):

```php
$body = json_encode($payload, JSON_UNESCAPED_UNICODE);
$signature = hash_hmac('sha256', $body, $secret); // hex
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
      "color": "сіро-блакитний",
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

| Поле | Тип | Обовʼязкове | Опис |
|---|---|---|---|
| `external_order_id` | string | **так** | Номер замовлення на сайті. **Має бути стабільним** — за ним працює захист від дублів. |
| `customer.first_name` / `last_name` | string | бажано | Імʼя / прізвище. |
| `customer.phone` | string | **дуже бажано** | Телефон у будь-якому форматі. По ньому CRM знаходить/обʼєднує клієнта. |
| `customer.email` | string | ні | Email. |
| `items[].external_id` | string | бажано | ID товару на сайті. Потрібен для «памʼяті» зіставлень. |
| `items[].sku` | string | **ключове** | **Варіантний SKU** (напр. `6027-36-37`), той самий, що в CRM. Саме по ньому товар мапиться автоматично в потрібний розмір. |
| `items[].name` | string | **так** | Назва (зберігається як знімок, показується якщо товар не розпізнано). |
| `items[].size` | string | бажано | Розмір (як на сайті). |
| `items[].color` | string | ні | Колір (знімок). |
| `items[].qty` | int | **так** | Кількість (≥ 1). |
| `items[].price` | number | **так** | Ціна за одиницю. |
| `delivery.type` | string | ні | `warehouse` (відділення), `courier` (адресна), `postomat`. За замовч. — відділення. |
| `delivery.city_name` | string | бажано | Місто **текстом** (CRM сама звʼяже з довідником Нової Пошти). |
| `delivery.warehouse_name` | string | бажано | Відділення/поштомат текстом. |
| `delivery.recipient_name` / `recipient_phone` | string | ні | Отримувач, якщо відрізняється від клієнта. |
| `payment.method` | string | ні | `cod` (накладений платіж), `prepay`, `card`… За замовч. `cod`. |
| `payment.total` | number | ні | Сума замовлення. |
| `payment.currency` / `currency` | string | ні | Валюта, за замовч. `UAH`. |
| `note` | string | ні | Коментар (потрапляє у внутрішній коментар замовлення). |

> **Найважливіше:** `items[].sku` має збігатися з SKU варіанту в CRM. Тоді товар чіпляється сам, без ручної роботи. Якщо SKU не збігся — замовлення все одно створиться, а позиція потрапить на ручне зіставлення (один раз, далі автоматично).

## 4. Відповіді

| Код | Коли | Тіло |
|---|---|---|
| `202 Accepted` | замовлення прийнято й оброблено | `{ "accepted": true, "raw_id": 1, "status": "processed", "order_id": 123, "needs_review": false }` |
| `200 OK` | дубль (цей `external_order_id` вже приймали) | `{ "accepted": true, "duplicate": true, "order_id": 123 }` |
| `401` | немає / невірний `X-Api-Key` або невірний підпис | `{ "message": "..." }` |
| `403` | інтеграцію вимкнено в CRM | `{ "message": "..." }` |

`needs_review: true` означає, що якийсь товар не розпізнано — замовлення створене, оператор домапить у CRM.

## 5. Приклад відправки (PHP, cURL)

```php
$payload = [
    'external_order_id' => (string) $order->id,
    'customer' => [
        'first_name' => $order->first_name,
        'last_name'  => $order->last_name,
        'phone'      => $order->phone,
    ],
    'items' => array_map(fn ($i) => [
        'external_id' => (string) $i->product_id,
        'sku'         => $i->sku,        // варіантний SKU = той самий, що в CRM
        'name'        => $i->title,
        'size'        => $i->size,
        'qty'         => (int) $i->qty,
        'price'       => (float) $i->price,
    ], $order->items),
    'delivery' => [
        'type'           => 'warehouse',
        'city_name'      => $order->city,
        'warehouse_name' => $order->warehouse,
    ],
    'payment'  => ['method' => 'cod', 'total' => (float) $order->total, 'currency' => 'UAH'],
    'currency' => 'UAH',
];

$body = json_encode($payload, JSON_UNESCAPED_UNICODE);
$secret = 'СЕКРЕТ_З_КАРТКИ_ІНТЕГРАЦІЇ';

$ch = curl_init('https://domcrm.com.ua/api/v1/orders/intake');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-Api-Key: КЛЮЧ_З_КАРТКИ_ІНТЕГРАЦІЇ',
        'X-Signature: ' . hash_hmac('sha256', $body, $secret),
    ],
]);
$response = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
// Бажано: при коді не 2xx — повторити пізніше (черга/cron на боці сайту).
```

## 6. Рекомендації для сайту

- **Стабільний `external_order_id`** — не змінюй його після створення замовлення.
- **Повтори при помилці** — якщо CRM відповіла не 2xx (або таймаут), постав у власну чергу й повтори згодом. Дублів не буде — захищає `external_order_id`.
- **Той самий SKU, що в CRM** — головна умова авто-мапінгу. Узгодьте довідник SKU один раз.
