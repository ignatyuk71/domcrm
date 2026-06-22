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
use App\Services\Orders\OrderService;
use App\Support\DeliveryStatusMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function __construct(private OrderService $orders)
    {
    }

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
                'delivery.activeWarehouseStatus',
                'payment:id,order_id,prepay_amount,currency,method',
                'items' => fn ($q) => $q
                    ->select('id', 'order_id', 'product_id', 'product_variant_id', 'product_title', 'sku', 'size', 'price', 'qty', 'total')
                    ->with(['product:id,main_photo_path,color_id', 'product.color:id,name']),
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
            ->when($request->filled('status_ids') || $request->filled('status_id') || $request->filled('status'), function ($q) use ($request) {
                $status = $request->get('status_ids', $request->get('status_id', $request->get('status')));
                if (is_array($status)) {
                    $values = $status;
                } else {
                    $statusString = trim((string) $status);
                    $values = $statusString === ''
                        ? []
                        : (str_contains($statusString, ',')
                            ? array_map('trim', explode(',', $statusString))
                            : [$statusString]);
                }

                $ids = array_values(array_filter(array_map(function ($value) {
                    $value = trim((string) $value);
                    if ($value === '') {
                        return null;
                    }
                    return ctype_digit($value) ? (int) $value : null;
                }, $values), fn ($value) => $value !== null && $value > 0));

                if (!$ids) {
                    return $q->whereRaw('1 = 0');
                }

                return $q->whereIn('status_id', $ids);
            })
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->string('payment_status')))
            ->when($request->filled('delivery_hold_days'), function ($q) use ($request) {
                $days = max((int) $request->get('delivery_hold_days'), 1);
                $thresholdDate = now()->subDays($days);
                $q->whereHas('delivery', function ($dq) use ($thresholdDate) {
                    $dq->where('delivery_status_code', 'at_warehouse')
                        ->whereHas('statusHistory', function ($hq) use ($thresholdDate) {
                            $hq->where('status_code', 'at_warehouse')
                                ->whereNull('exited_at')
                                ->where('entered_at', '<=', $thresholdDate);
                        });
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

        $statusCounts = Order::query()
            ->whereNotNull('status_id')
            ->select('status_id', DB::raw('COUNT(*) as count'))
            ->groupBy('status_id')
            ->pluck('count', 'status_id')
            ->map(fn ($count) => (int) $count)
            ->all();

        $payload = $orders->toArray();
        $payload['status_counts'] = $statusCounts;

        return response()->json($payload);
    }

    /**
     * Відображення замовлення:
     * - для звичайного переходу в браузері повертаємо HTML-сторінку;
     * - для XHR/API-запитів повертаємо JSON з даними.
     */
    public function show(Request $request, Order $order)
    {
        if (!$request->expectsJson()) {
            return view('orders.show', ['orderId' => $order->id]);
        }

        $order->load([
            'customer' => fn ($q) => $q->withCount('orders'),
            'statusRef',
            'source',
            'tags',
            'delivery',
            'items.product',
            'items.variant',
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
            'items.*.product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],

            'payment.method' => ['required', 'string', 'max:32'],
            'payment.prepay_amount' => ['nullable', 'numeric', 'min:0'],
            'payment.currency' => ['required', 'string', 'max:3'],

            'delivery.carrier' => ['nullable', 'string', 'max:32'],
            'delivery.delivery_type' => ['required', 'string', 'max:32'],
            'delivery.payer' => ['nullable', 'string', 'max:32'],
            'delivery.ttn' => ['nullable', 'string', 'max:64'],
            'delivery.city_ref' => ['nullable', 'string', 'max:64'],
            'delivery.settlement_ref' => ['nullable', 'string', 'max:64'],
            'delivery.city_name' => ['nullable', 'string', 'max:255'],
            'delivery.warehouse_ref' => ['nullable', 'string', 'max:64'],
            'delivery.warehouse_name' => ['nullable', 'string', 'max:255'],
            'delivery.street_name' => ['nullable', 'string', 'max:255'],
            'delivery.street_ref' => ['nullable', 'string', 'max:64'],
            'delivery.address_ref' => ['nullable', 'string', 'max:64'],
            'delivery.building' => ['nullable', 'string', 'max:64'],
            'delivery.apartment' => ['nullable', 'string', 'max:64'],
            'delivery.address_note' => ['nullable', 'string', 'max:255'],
            'delivery.recipient_name' => ['nullable', 'string', 'max:255'],
            'delivery.recipient_phone' => ['nullable', 'string', 'max:64'],

            'tag_ids' => ['array'],
            'tag_ids.*' => ['nullable'],
        ]);

        $this->assertDeliverySelected($data);

        $order = $this->orders->create($data, $request->user()?->id);

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
            'items.*.product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],

            'payment.method' => ['required', 'string', 'max:32'],
            'payment.prepay_amount' => ['nullable', 'numeric', 'min:0'],
            'payment.currency' => ['required', 'string', 'max:3'],

            'delivery.carrier' => ['nullable', 'string', 'max:32'],
            'delivery.delivery_type' => ['required', 'string', 'max:32'],
            'delivery.payer' => ['nullable', 'string', 'max:32'],
            'delivery.ttn' => ['nullable', 'string', 'max:64'],
            'delivery.city_ref' => ['nullable', 'string', 'max:64'],
            'delivery.settlement_ref' => ['nullable', 'string', 'max:64'],
            'delivery.city_name' => ['nullable', 'string', 'max:255'],
            'delivery.warehouse_ref' => ['nullable', 'string', 'max:64'],
            'delivery.warehouse_name' => ['nullable', 'string', 'max:255'],
            'delivery.street_name' => ['nullable', 'string', 'max:255'],
            'delivery.street_ref' => ['nullable', 'string', 'max:64'],
            'delivery.address_ref' => ['nullable', 'string', 'max:64'],
            'delivery.building' => ['nullable', 'string', 'max:64'],
            'delivery.apartment' => ['nullable', 'string', 'max:64'],
            'delivery.address_note' => ['nullable', 'string', 'max:255'],
            'delivery.recipient_name' => ['nullable', 'string', 'max:255'],
            'delivery.recipient_phone' => ['nullable', 'string', 'max:64'],

            'tag_ids' => ['array'],
            'tag_ids.*' => ['nullable'],
        ]);

        $this->assertDeliverySelected($data);

        $this->orders->update($order, $data);

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

        $tagIds = $this->orders->resolveTagIds($data['tag_ids'] ?? []);
        $order->tags()->sync($tagIds);

        $tags = $order->tags()
            ->select('tags.id', 'tags.name', 'tags.code', 'tags.color', 'tags.icon')
            ->get();

        return response()->json(['data' => $tags]);
    }

    /** Оновлення внутрішнього коментаря. */
    public function updateComment(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'comment_internal' => ['nullable', 'string'],
        ]);

        $order->update([
            'comment_internal' => $data['comment_internal'] ?? null,
        ]);

        return response()->json([
            'comment_internal' => $order->comment_internal,
        ]);
    }

    /**
     * Не даємо зберегти, якщо назву міста/відділення ВПИСАЛИ, але не ОБРАЛИ зі
     * списку (ref порожній) — інакше ТТН потім мовчки впаде. Курʼєрську вулицю
     * НЕ перевіряємо: її може не бути в базі НП (легітимний кейс).
     */
    protected function assertDeliverySelected(array $data): void
    {
        $delivery = $data['delivery'] ?? [];
        $errors = [];

        if (trim((string) ($delivery['city_name'] ?? '')) !== '' && empty($delivery['city_ref'])) {
            $errors['delivery.city_name'] = 'Оберіть місто зі списку підказок.';
        }

        if (($delivery['delivery_type'] ?? 'warehouse') !== 'courier'
            && trim((string) ($delivery['warehouse_name'] ?? '')) !== ''
            && empty($delivery['warehouse_ref'])) {
            $errors['delivery.warehouse_name'] = 'Оберіть відділення або поштомат зі списку.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    protected function resolveStatusIdByCode(?string $code, string $type = 'order'): ?int
    {
        if (!$code) return null;
        return Status::where('code', $code)->where('type', $type)->value('id');
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
        $delivery = $order->delivery;
        if (!$delivery || !$delivery->city_ref) {
            return response()->json([
                'message' => 'Не заповнені дані міста',
                'details' => [
                    'city' => $delivery->city_ref ?? 'null',
                ]
            ], 422);
        }

        if (($delivery->delivery_type ?? 'warehouse') === 'courier') {
            if (!$delivery->street_ref || !$delivery->building) {
                return response()->json([
                    'message' => 'Не заповнені дані адреси для курʼєра',
                    'details' => [
                        'street_ref' => $delivery->street_ref ?? 'null',
                        'building' => $delivery->building ?? 'null',
                    ]
                ], 422);
            }
        } else {
            if (!$delivery->warehouse_ref) {
                return response()->json([
                    'message' => 'Не заповнені дані міста або відділення',
                    'details' => [
                        'city' => $delivery->city_ref ?? 'null',
                        'warehouse' => $delivery->warehouse_ref ?? 'null'
                    ]
                ], 422);
            }
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
                'search_blob' => $this->orders->buildSearchBlob($order->customer, $ttnNumber)
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

    /** ГЕНЕРАЦІЯ ТТН НОВОЇ ПОШТИ ДЛЯ КУРʼЄРА */
    public function generateTTNCourier(\App\Models\Order $order, \App\Services\NovaPoshtaService $np): JsonResponse
    {
        $order->refresh();
        $order->load(['delivery', 'customer', 'items.product']);

        $delivery = $order->delivery;
        if (!$delivery || !$delivery->settlement_ref || !$delivery->city_ref) {
            return response()->json([
                'message' => 'Не заповнені дані міста/населеного пункту (CityRef/SettlementRef)',
                'details' => [
                    'city_ref' => $delivery->city_ref ?? 'null',
                    'settlement_ref' => $delivery->settlement_ref ?? 'null',
                ]
            ], 422);
        }

        if (!$delivery->street_ref || !$delivery->building) {
            return response()->json([
                'message' => 'Не заповнені дані адреси для курʼєра',
                'details' => [
                    'street_ref' => $delivery->street_ref ?? 'null',
                    'building' => $delivery->building ?? 'null',
                ]
            ], 422);
        }

        $result = $np->createWaybillCourier($order);

        if (isset($result['success']) && $result['success']) {
            $ttnData = $result['data'][0];
            $ttnNumber = $ttnData['IntDocNumber'];
            $ttnRef    = $ttnData['Ref'];

            $order->delivery->update([
                'ttn'     => $ttnNumber,
                'ttn_ref' => $ttnRef
            ]);

            $order->update([
                'search_blob' => $this->orders->buildSearchBlob($order->customer, $ttnNumber)
            ]);

            return response()->json([
                'success' => true,
                'ttn'     => $ttnNumber,
                'ref'     => $ttnRef
            ]);
        }

        $error = $result['errors'][0] ?? 'Помилка Нової Пошти';
        \Illuminate\Support\Facades\Log::error('NovaPoshta courier: generateTTNCourier failed', [
            'order_id' => $order->id,
            'delivery_id' => $order->delivery?->id,
            'settlement_ref' => $order->delivery?->settlement_ref,
            'street_ref' => $order->delivery?->street_ref,
            'street_name' => $order->delivery?->street_name,
            'building' => $order->delivery?->building,
            'apartment' => $order->delivery?->apartment,
            'np_errors' => $result['errors'] ?? null,
            'np_warnings' => $result['warnings'] ?? null,
        ]);
        return response()->json([
            'message' => $error,
            'sent_data_check' => [
                'settlement_ref'  => $order->delivery->settlement_ref,
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
            $order->delivery->statusHistory()->whereNull('exited_at')->update(['exited_at' => now()]);

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
        $link = $np->getPrintLink($ttn);

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
        $delivery->syncStatusHistory($normalized, now());
        $delivery->load('activeWarehouseStatus');

        $npCode = (int) ($data['StatusCode'] ?? $data['Status'] ?? 0);
        $newStatusCode = DeliveryStatusMapper::getCrmStatusCode($npCode);
        if ($newStatusCode) {
            $newStatusId = Status::where('code', $newStatusCode)
                ->where('type', 'order')
                ->value('id');

            if ($newStatusId) {
                if ($order->status_id !== $newStatusId || $order->status !== $newStatusCode) {
                    $order->update([
                        'status_id' => $newStatusId,
                        'status' => $newStatusCode,
                    ]);
                }
            } else {
                Log::warning('NovaPoshta status mapped to unknown CRM status code (manual)', [
                    'order_id' => $order->id,
                    'np_code' => $npCode,
                    'status_code' => $newStatusCode,
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'delivery_status_code' => $delivery->delivery_status_code,
            'delivery_status_label' => $delivery->delivery_status_label,
            'delivery_status_description' => $delivery->delivery_status_description,
            'delivery_status_color' => $delivery->delivery_status_color,
            'delivery_status_icon' => $delivery->delivery_status_icon,
            'delivery_status_updated_at' => $delivery->delivery_status_updated_at,
            'last_tracked_at' => $delivery->last_tracked_at,
            'warehouse_entered_at' => $delivery->activeWarehouseStatus?->entered_at,
        ]);
    }
}
