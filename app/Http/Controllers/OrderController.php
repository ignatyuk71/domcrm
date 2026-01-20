<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Tag;
use App\Models\Status;
use App\Models\OrderSource;
use App\Support\DeliveryStatusMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    /** Список замовлень (для перегляду збережених записів). */
    public function index()
    {
        $orders = Order::with(['customer', 'tags'])
            ->latest('id')
            ->paginate(25);

        return view('orders.index', compact('orders'));
    }

    /** JSON-список замовлень з базовими фільтрами (для Vue-таблиці). */
    public function list(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 20), 100);

        $orders = Order::query()
            ->with([
                'customer' => fn ($q) => $q->withCount('orders'),
                'statusRef',
                'source',
                'tags',
                'delivery',
                'payment:id,order_id,prepay_amount,currency,method',
                'items' => fn ($q) => $q
                    ->select('id', 'order_id', 'product_id', 'product_title', 'sku', 'size', 'price', 'qty', 'total')
                    ->with('product:id,main_photo_path'),
                'latestFiscalReceipt' => fn ($q) => $q->select(
                    'fiscal_receipts.id',
                    'fiscal_receipts.order_id',
                    'fiscal_receipts.status',
                    'fiscal_receipts.type',
                    'fiscal_receipts.fiscal_code',
                    'fiscal_receipts.check_link',
                    'fiscal_receipts.error_message',
                    'fiscal_receipts.uuid',
                    'fiscal_receipts.total_amount',
                    'fiscal_receipts.created_at'
                ),
            ])
            ->withSum('items', 'total')
            ->withCount('items')
            ->when($request->filled('status'), function ($q) use ($request) {
                $status = $request->get('status');
                if (is_array($status)) {
                    $values = array_filter($status, fn ($v) => $v !== null && $v !== '');
                    if ($values) {
                        return $q->whereIn('status', $values);
                    }
                }
                $statusString = (string) $status;
                if (str_contains($statusString, ',')) {
                    $parts = array_filter(array_map('trim', explode(',', $statusString)));
                    if ($parts) {
                        return $q->whereIn('status', $parts);
                    }
                }
                return $q->where('status', $statusString);
            })
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->string('payment_status')))
            ->when($request->filled('delivery_hold_days'), function ($q) use ($request) {
                $days = max((int) $request->get('delivery_hold_days'), 1);
                $thresholdDate = now()->subDays($days)->toDateString();
                $q->whereHas('delivery', function ($dq) use ($thresholdDate) {
                    $dq->where('delivery_status_code', 'at_warehouse')
                        ->whereNotNull('delivery_status_updated_at')
                        ->whereDate('delivery_status_updated_at', '<=', $thresholdDate);
                });
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = trim((string) $request->get('search'));
                $q->where(function ($inner) use ($term) {
                    $inner->where('order_number', 'like', "%{$term}%")
                        ->orWhere('search_blob', 'like', "%{$term}%")
                        // ДОДАНО: Пошук за номером ТТН
                        ->orWhereHas('delivery', function ($dq) use ($term) {
                            $dq->where('ttn', 'like', "%{$term}%");
                        })
                        ->orWhereHas('customer', function ($cq) use ($term) {
                            $cq->where('first_name', 'like', "%{$term}%")
                                ->orWhere('last_name', 'like', "%{$term}%")
                                ->orWhere('phone', 'like', "%{$term}%")
                                ->orWhere('email', 'like', "%{$term}%");
                        });
                });
            })
            ->when($request->filled('tag_ids'), function ($q) use ($request) {
                $tagIds = array_filter((array) $request->get('tag_ids'));
                if (!empty($tagIds)) {
                    $q->whereHas('tags', fn ($tq) => $tq->whereIn('tags.id', $tagIds));
                }
            })
            ->latest('id')
            ->paginate($perPage);

        return response()->json($orders);
    }

    public function show(Order $order): JsonResponse
    {
        $order->load([
            'customer' => fn ($q) => $q->withCount('orders'),
            'statusRef',
            'tags',
            'delivery',
            'items.product',
            'payment',
            'latestFiscalReceipt' => fn ($q) => $q->select(
                'fiscal_receipts.id',
                'fiscal_receipts.order_id',
                'fiscal_receipts.status',
                'fiscal_receipts.type',
                'fiscal_receipts.fiscal_code',
                'fiscal_receipts.check_link',
                'fiscal_receipts.error_message',
                'fiscal_receipts.uuid',
                'fiscal_receipts.total_amount',
                'fiscal_receipts.created_at'
            ),
        ]);

        return response()->json(['data' => $order]);
    }

    /** Збереження замовлення разом із покупцем, товарами, доставкою та оплатою. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer.first_name' => ['nullable', 'string', 'max:255'],
            'customer.last_name' => ['nullable', 'string', 'max:255'],
            'customer.phone' => ['nullable', 'string', 'max:32'],
            'customer.email' => ['nullable', 'string', 'max:255'],

            'order.source' => ['nullable', 'string', 'max:32'],
            'order.source_id' => ['nullable', 'integer', 'exists:order_sources,id'],
            'order.status' => ['required', 'string', 'max:32'],
            'order.payment_status' => ['required', 'string', 'max:32'],
            'order.currency' => ['required', 'string', 'max:3'],
            'order.comment_internal' => ['nullable', 'string'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.sku' => ['nullable', 'string', 'max:64'],
            'items.*.title' => ['nullable', 'string', 'max:255'],
            'items.*.size' => ['nullable', 'string', 'max:64'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],

            'payment.method' => ['required', 'string', 'max:32'],
            'payment.prepay_amount' => ['nullable', 'numeric', 'min:0'],
            'payment.currency' => ['required', 'string', 'max:3'],

            'delivery.carrier' => ['nullable', 'string', 'max:32'],
            'delivery.delivery_type' => ['required', 'string', 'max:32'],
            'delivery.payer' => ['nullable', 'string', 'max:32'],
            'delivery.ttn' => ['nullable', 'string', 'max:64'],
            'delivery.city_ref' => ['nullable', 'string', 'max:64'],
            'delivery.city_name' => ['nullable', 'string', 'max:255'],
            'delivery.warehouse_ref' => ['nullable', 'string', 'max:64'],
            'delivery.warehouse_name' => ['nullable', 'string', 'max:255'],
            'delivery.street_name' => ['nullable', 'string', 'max:255'],
            'delivery.building' => ['nullable', 'string', 'max:64'],
            'delivery.apartment' => ['nullable', 'string', 'max:64'],
            'delivery.address_note' => ['nullable', 'string', 'max:255'],
            'delivery.recipient_name' => ['nullable', 'string', 'max:255'],
            'delivery.recipient_phone' => ['nullable', 'string', 'max:64'],

            'tag_ids' => ['array'],
            'tag_ids.*' => ['nullable'],
        ]);

        $order = DB::transaction(function () use ($data, $request) {
            // Клієнт: шукаємо за телефоном або створюємо нового
            $customer = null;
            $phone = trim($data['customer']['phone'] ?? '');
            if ($phone) {
                $customer = Customer::firstOrCreate(
                    ['phone' => $phone],
                    [
                        'first_name' => $data['customer']['first_name'] ?? null,
                        'last_name' => $data['customer']['last_name'] ?? null,
                        'email' => $data['customer']['email'] ?? null,
                    ]
                );
            } elseif (!empty($data['customer'])) {
                $customer = Customer::create([
                    'first_name' => $data['customer']['first_name'] ?? null,
                    'last_name' => $data['customer']['last_name'] ?? null,
                    'phone' => null,
                    'email' => $data['customer']['email'] ?? null,
                ]);
            }

            // Генерація тимчасового номера (потім замінюємо на індекс)
            $orderNumber = $this->generateOrderNumber();
            $statusCode = $data['order']['status'] ?? 'new';
            $statusId = $this->resolveStatusId($statusCode, 'order');
            $sourceId = $data['order']['source_id'] ?? $this->resolveSourceId($data['order']['source'] ?? null);

            // Створення замовлення
            $order = Order::create([
                'order_number' => $orderNumber,
                'source' => $data['order']['source'] ?? null,
                'source_id' => $sourceId,
                'status' => $statusCode ?? 'new',
                'status_id' => $statusId,
                'payment_status' => $data['order']['payment_status'] ?? 'unpaid',
                'customer_id' => $customer?->id,
                'manager_id' => $request->user()?->id,
                'currency' => $data['order']['currency'] ?? 'UAH',
                'comment_internal' => $data['order']['comment_internal'] ?? null,
                // ДОДАНО: передаємо ТТН у пошуковий блоб
                'search_blob' => $this->buildSearchBlob($customer, $data['delivery']['ttn'] ?? null),
            ]);

            // Використовуємо індекс як номер замовлення
            $order->update(['order_number' => (string) $order->id]);

            // Теги (звʼязок через pivot)
            if (!empty($data['tag_ids'])) {
                $order->tags()->sync($this->resolveTagIds($data['tag_ids']));
            }

            // Товари
            foreach ($data['items'] as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item['product_id'] ?? null,
                    'product_title' => $item['title'] ?? null,
                    'sku' => $item['sku'] ?? null,
                    'size' => $item['size'] ?? null,
                    'color' => $item['color'] ?? null,
                    'price' => $item['price'] ?? 0,
                    'qty' => $item['qty'] ?? 1,
                    'total' => ($item['price'] ?? 0) * ($item['qty'] ?? 1),
                ]);
            }

            // Оплата
            OrderPayment::create([
                'order_id' => $order->id,
                'method' => $data['payment']['method'] ?? 'cod',
                'prepay_amount' => $data['payment']['prepay_amount'] ?? null,
                'currency' => $data['payment']['currency'] ?? ($data['order']['currency'] ?? 'UAH'),
                'note' => $data['payment']['note'] ?? null,
            ]);

            // Доставка
            OrderDelivery::create([
                'order_id' => $order->id,
                'carrier' => $data['delivery']['carrier'] ?? 'nova_poshta',
                'delivery_type' => $data['delivery']['delivery_type'] ?? 'warehouse',
                'delivery_payer' => $data['delivery']['payer'] ?? 'recipient',
                'ttn' => $data['delivery']['ttn'] ?? null,
                'city_ref' => $data['delivery']['city_ref'] ?? null,
                'city_name' => $data['delivery']['city_name'] ?? null,
                'warehouse_ref' => $data['delivery']['warehouse_ref'] ?? null,
                'warehouse_name' => $data['delivery']['warehouse_name'] ?? null,
                'street_name' => $data['delivery']['street_name'] ?? null,
                'building' => $data['delivery']['building'] ?? null,
                'apartment' => $data['delivery']['apartment'] ?? null,
                'address_note' => $data['delivery']['address_note'] ?? null,
                'recipient_name' => $data['delivery']['recipient_name'] ?? null,
                'recipient_phone' => $data['delivery']['recipient_phone'] ?? null,
            ]);

            return $order;
        });

        return response()->json([
            'data' => $order->load([
                'customer' => fn ($q) => $q->withCount('orders'),
                'items',
                'payment',
                'delivery',
                'tags'
            ]),
        ], 201);
    }

    public function update(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'customer.first_name' => ['nullable', 'string', 'max:255'],
            'customer.last_name' => ['nullable', 'string', 'max:255'],
            'customer.phone' => ['nullable', 'string', 'max:32'],
            'customer.email' => ['nullable', 'string', 'max:255'],

            'order.source' => ['nullable', 'string', 'max:32'],
            'order.source_id' => ['nullable', 'integer', 'exists:order_sources,id'],
            'order.status' => ['required', 'string', 'max:32'],
            'order.payment_status' => ['required', 'string', 'max:32'],
            'order.currency' => ['required', 'string', 'max:3'],
            'order.comment_internal' => ['nullable', 'string'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.sku' => ['nullable', 'string', 'max:64'],
            'items.*.title' => ['nullable', 'string', 'max:255'],
            'items.*.size' => ['nullable', 'string', 'max:64'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],

            'payment.method' => ['required', 'string', 'max:32'],
            'payment.prepay_amount' => ['nullable', 'numeric', 'min:0'],
            'payment.currency' => ['required', 'string', 'max:3'],

            'delivery.carrier' => ['nullable', 'string', 'max:32'],
            'delivery.delivery_type' => ['required', 'string', 'max:32'],
            'delivery.payer' => ['nullable', 'string', 'max:32'],
            'delivery.ttn' => ['nullable', 'string', 'max:64'],
            'delivery.city_ref' => ['nullable', 'string', 'max:64'],
            'delivery.city_name' => ['nullable', 'string', 'max:255'],
            'delivery.warehouse_ref' => ['nullable', 'string', 'max:64'],
            'delivery.warehouse_name' => ['nullable', 'string', 'max:255'],
            'delivery.street_name' => ['nullable', 'string', 'max:255'],
            'delivery.building' => ['nullable', 'string', 'max:64'],
            'delivery.apartment' => ['nullable', 'string', 'max:64'],
            'delivery.address_note' => ['nullable', 'string', 'max:255'],
            'delivery.recipient_name' => ['nullable', 'string', 'max:255'],
            'delivery.recipient_phone' => ['nullable', 'string', 'max:64'],

            'tag_ids' => ['array'],
            'tag_ids.*' => ['nullable'],
        ]);

        DB::transaction(function () use ($data, $request, $order) {
            // Клієнт
            $customer = $order->customer ?: new Customer();
            $customer->first_name = $data['customer']['first_name'] ?? null;
            $customer->last_name = $data['customer']['last_name'] ?? null;
            $customer->phone = $data['customer']['phone'] ?? null;
            $customer->email = $data['customer']['email'] ?? null;
            $customer->save();

            $statusCode = $data['order']['status'] ?? 'new';
            $statusId = $this->resolveStatusId($statusCode, 'order');
            $sourceId = $data['order']['source_id'] ?? $this->resolveSourceId($data['order']['source'] ?? null);

            // Замовлення
            $order->update([
                'source' => $data['order']['source'] ?? null,
                'source_id' => $sourceId,
                'status' => $statusCode,
                'status_id' => $statusId,
                'payment_status' => $data['order']['payment_status'] ?? 'unpaid',
                'customer_id' => $customer->id,
                'currency' => $data['order']['currency'] ?? 'UAH',
                'comment_internal' => $data['order']['comment_internal'] ?? null,
                // ДОДАНО: передаємо ТТН у пошуковий блоб
                'search_blob' => $this->buildSearchBlob($customer, $data['delivery']['ttn'] ?? null),
            ]);

            // Теги
            $order->tags()->sync($this->resolveTagIds($data['tag_ids'] ?? []));

            // Позиції: простий варіант — видалити старі і створити нові
            $order->items()->delete();
            foreach ($data['items'] as $item) {
                $order->items()->create([
                    'product_id' => $item['product_id'] ?? null,
                    'product_title' => $item['title'] ?? null,
                    'sku' => $item['sku'] ?? null,
                    'size' => $item['size'] ?? null,
                    'color' => $item['color'] ?? null,
                    'price' => $item['price'] ?? 0,
                    'qty' => $item['qty'] ?? 1,
                    'total' => ($item['price'] ?? 0) * ($item['qty'] ?? 1),
                ]);
            }

            // Оплата
            $order->payment()->updateOrCreate(
                ['order_id' => $order->id],
                [
                    'method' => $data['payment']['method'] ?? 'cod',
                    'prepay_amount' => $data['payment']['prepay_amount'] ?? null,
                    'currency' => $data['payment']['currency'] ?? ($data['order']['currency'] ?? 'UAH'),
                    'note' => $data['payment']['note'] ?? null,
                ]
            );

            // Доставка
            $order->delivery()->updateOrCreate(
                ['order_id' => $order->id],
                [
                    'carrier' => $data['delivery']['carrier'] ?? 'nova_poshta',
                    'delivery_type' => $data['delivery']['delivery_type'] ?? 'warehouse',
                    'delivery_payer' => $data['delivery']['payer'] ?? 'recipient',
                    'ttn' => $data['delivery']['ttn'] ?? null,
                    'city_ref' => $data['delivery']['city_ref'] ?? null,
                    'city_name' => $data['delivery']['city_name'] ?? null,
                    'warehouse_ref' => $data['delivery']['warehouse_ref'] ?? null,
                    'warehouse_name' => $data['delivery']['warehouse_name'] ?? null,
                    'street_name' => $data['delivery']['street_name'] ?? null,
                    'building' => $data['delivery']['building'] ?? null,
                    'apartment' => $data['delivery']['apartment'] ?? null,
                    'address_note' => $data['delivery']['address_note'] ?? null,
                    'recipient_name' => $data['delivery']['recipient_name'] ?? null,
                    'recipient_phone' => $data['delivery']['recipient_phone'] ?? null,
                ]
            );
        });

        return response()->json([
            'data' => $order->load([
                'customer' => fn ($q) => $q->withCount('orders'),
                'items',
                'payment',
                'delivery',
                'tags',
                'statusRef'
            ]),
        ]);
    }

    /** Видалення замовлення та повʼязаних сутностей. */
    public function destroy(Order $order): JsonResponse
    {
        // За потреби можна додати Gate/Policy
        if (Gate::has('delete-order') && Gate::denies('delete-order', $order)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        DB::transaction(function () use ($order) {
            $order->tags()->detach();
            // Видаляємо повʼязане, щоб очистити всі статуси доставки/оплати/позиції
            $order->delivery()->delete();
            $order->payment()->delete();
            $order->items()->delete();
            $order->delete();
        });

        return response()->json(['message' => 'Deleted']);
    }

    /** Оновлення тегів замовлення. */
    public function updateTags(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'tag_ids' => ['array'],
            'tag_ids.*' => ['nullable'],
        ]);

        $tagIds = $this->resolveTagIds($data['tag_ids'] ?? []);
        $order->tags()->sync($tagIds);

        $tags = $order->tags()
            ->select('tags.id', 'tags.name', 'tags.code', 'tags.color', 'tags.icon')
            ->get();

        return response()->json(['data' => $tags]);
    }

    /** Тимчасовий номер (замінимо на id після створення). */
    protected function generateOrderNumber(): string
    {
        return 'TMP-' . now()->format('YmdHis') . '-' . random_int(100, 999);
    }

    /** Формуємо пошуковий blob для швидких фільтрів. */
    protected function buildSearchBlob(?Customer $customer, $ttn = null): ?string
    {
        if (!$customer) return $ttn;

        // ОНОВЛЕНО: Додаємо ТТН в пошук
        return trim(implode(' ', array_filter([
            $customer->full_name,
            $customer->phone,
            $customer->email,
            $ttn
        ])));
    }

    /**
     * Приймаємо tag_ids як масив числових id або кодів/назв;
     * повертаємо масив id з таблиці tags, створюючи відсутні за кодом.
     */
    protected function resolveTagIds(array $tagIds): array
    {
        $ids = [];
        foreach ($tagIds as $tag) {
            if (is_numeric($tag)) {
                // Якщо передали id
                $ids[] = (int) $tag;
            } else {
                // Якщо передали код/назву
                $code = trim((string) $tag);
                if ($code === '') {
                    continue;
                }

                $tagModel = Tag::firstOrCreate(
                    ['code' => $code],
                    ['name' => $code]
                );
                $ids[] = $tagModel->id;
            }
        }
        return $ids;
    }

    protected function resolveStatusId(?string $code, string $type = 'order'): ?int
    {
        if (!$code) return null;
        return Status::where('code', $code)->where('type', $type)->value('id');
    }

    protected function resolveStatusIdByCode(?string $code, string $type = 'order'): ?int
    {
        if (!$code) return null;
        return Status::where('code', $code)->where('type', $type)->value('id');
    }

    protected function resolveSourceId(?string $code): ?int
    {
        if (!$code) return null;
        return OrderSource::where('code', $code)->value('id');
    }

    /** Оновлення статусу замовлення через статуси-довідник. */
    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'status_id' => ['required', 'integer', 'exists:statuses,id'],
        ]);

        $status = Status::where('id', $data['status_id'])
            ->where('type', 'order')
            ->firstOrFail();

        $order->status_id = $status->id;
        $order->status = $status->code; // для сумісності зі старим полем
        $order->save();

        return response()->json([
            'data' => [
                'id' => $status->id,
                'code' => $status->code,
                'name' => $status->name,
                'icon' => $status->icon,
                'color' => $status->color,
            ],
        ]);
    }

    /** ГЕНЕРАЦІЯ ТТН НОВОЇ ПОШТИ (З ПІДТРИМКОЮ TTN_REF) */
    public function generateTTN(\App\Models\Order $order, \App\Services\NovaPoshtaService $np): JsonResponse
    {
        // Примусово оновлюємо дані з бази, щоб побачити найсвіжіші зміни
        $order->refresh();
        $order->load(['delivery', 'customer', 'items.product']);

        // 1. Перевіряємо, чи заповнені дані для доставки
        if (!$order->delivery || !$order->delivery->city_ref || !$order->delivery->warehouse_ref) {
            return response()->json([
                'message' => 'Не заповнені дані міста або відділення',
                'details' => [
                    'city' => $order->delivery->city_ref ?? 'null',
                    'warehouse' => $order->delivery->warehouse_ref ?? 'null'
                ]
            ], 422);
        }

        // 2. Викликаємо сервіс для створення накладної
        $result = $np->createWaybill($order);

        if (isset($result['success']) && $result['success']) {
            $ttnData = $result['data'][0];
            
            $ttnNumber = $ttnData['IntDocNumber']; // Номер накладної (14 цифр)
            $ttnRef    = $ttnData['Ref'];          // Внутрішній ідентифікатор НП (UUID)
            
            // 3. Оновлюємо ТТН та ТТН_REF у моделі доставки
            $order->delivery->update([
                'ttn'     => $ttnNumber,
                'ttn_ref' => $ttnRef
            ]);

            // 4. Оновлюємо пошуковий блоб замовлення (для швидкого пошуку по ТТН)
            $order->update([
                'search_blob' => $this->buildSearchBlob($order->customer, $ttnNumber)
            ]);

            return response()->json([
                'success' => true,
                'ttn'     => $ttnNumber,
                'ref'     => $ttnRef 
            ]);
        }

        // Якщо помилка, повертаємо її разом з даними для перевірки
        $error = $result['errors'][0] ?? 'Помилка Нової Пошти';
        return response()->json([
            'message' => $error,
            'sent_data_check' => [
                'city'  => $order->delivery->city_ref,
                'phone' => $order->customer->phone
            ]
        ], 400);
    }

    /** АНУЛЮВАННЯ ТТН */
    public function cancelTTN(\App\Models\Order $order, \App\Services\NovaPoshtaService $np): \Illuminate\Http\JsonResponse
    {
        $order->load('delivery');
        $ttnRef = $order->delivery->ttn_ref;

        if (!$ttnRef) {
            return response()->json(['message' => 'Ref накладної не знайдено в базі'], 422);
        }

        $result = $np->deleteWaybill($ttnRef);

        if (isset($result['success']) && $result['success']) {
            // Очищаємо ТТН та Ref в базі
            $order->delivery->update([
                'ttn' => null,
                'ttn_ref' => null,
                'delivery_status_code' => null,
                'delivery_status_label' => null,
                'delivery_status_description' => null,
                'delivery_status_color' => null,
                'delivery_status_icon' => null,
                'delivery_status_updated_at' => null,
                'last_tracked_at' => null,
            ]);

            return response()->json([
                'success' => true, 
                'message' => 'ТТН успішно анульовано'
            ]);
        }

        return response()->json([
            'message' => $result['errors'][0] ?? 'Помилка Нової Пошти при видаленні'
        ], 400);
    }

    /** ОТРИМАННЯ ПОСИЛАННЯ НА ДРУК МАЛЕНЬКИХ НАКЛЕЙОК (100х100) */
    public function printTTN(\App\Models\Order $order, \App\Services\NovaPoshtaService $np): \Illuminate\Http\JsonResponse
    {
        $order->load('delivery');
        $ttn = $order->delivery->ttn;

        if (!$ttn) {
            return response()->json(['message' => 'ТТН ще не створена'], 422);
        }

        // Параметр /type/pdf/zebra/1 генерує саме маленьку наклейку для термопринтера
        $link = "https://my.novaposhta.ua/orders/printMarkings/orders[]/{$ttn}/type/pdf/zebra/1/apiKey/" . config('services.nova_poshta.api_key');

        return response()->json([
            'success' => true,
            'print_url' => $link
        ]);
    }

    /** Ручне оновлення статусу доставки за ТТН (без очікування cron). */
    public function trackDelivery(Order $order, \App\Services\NovaPoshtaService $np): JsonResponse
    {
        $order->load('delivery', 'customer');
        $delivery = $order->delivery;

        if (!$delivery || !$delivery->ttn) {
            return response()->json(['message' => 'ТТН відсутня'], 422);
        }

        $response = $np->getStatuses([
            [
                'DocumentNumber' => $delivery->ttn,
                'Phone' => $delivery->recipient_phone ?? $order->customer?->phone,
            ],
        ]);

        if (!($response['success'] ?? false)) {
            Log::warning('NovaPoshta tracking error (manual)', [
                'order_id' => $order->id,
                'ttn' => $delivery->ttn,
                'errors' => $response['errors'] ?? ['unknown'],
            ]);

            return response()->json([
                'message' => $response['errors'][0] ?? 'Помилка Нової Пошти',
            ], 400);
        }

        $data = $response['data'][0] ?? null;
        if (!$data) {
            return response()->json([
                'message' => 'Статус не знайдено для цієї ТТН',
            ], 404);
        }

        $normalized = DeliveryStatusMapper::map($data);
        $delivery->forceFill([
            'delivery_status_code' => $normalized['code'],
            'delivery_status_label' => $normalized['label'],
            'delivery_status_description' => $normalized['description'],
            'delivery_status_color' => $normalized['color'],
            'delivery_status_icon' => $normalized['icon'],
            'delivery_status_updated_at' => now(),
            'last_tracked_at' => now(),
        ])->saveQuietly();

        return response()->json([
            'success' => true,
            'delivery_status_code' => $delivery->delivery_status_code,
            'delivery_status_label' => $delivery->delivery_status_label,
            'delivery_status_description' => $delivery->delivery_status_description,
            'delivery_status_color' => $delivery->delivery_status_color,
            'delivery_status_icon' => $delivery->delivery_status_icon,
            'delivery_status_updated_at' => $delivery->delivery_status_updated_at,
            'last_tracked_at' => $delivery->last_tracked_at,
        ]);
    }
}
