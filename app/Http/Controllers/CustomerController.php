<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    /**
     * Пошук покупців за телефоном/імʼям/email для підказок у формі замовлення.
     */
    public function index(Request $request): JsonResponse|View
    {
        if (!$request->expectsJson() && !$request->ajax()) {
            return view('customers.index');
        }

        $query = trim((string) $request->get('q', ''));

        if ($query !== '') {
            $normalizedPhone = PhoneNormalizer::normalize($query);

            // Повний український номер шукаємо точним збігом по індексу.
            // Широкий fallback нижче лишається для legacy-даних без backfill.
            if ($this->isNormalizedUkrainianPhone($normalizedPhone)) {
                $customers = Customer::query()
                    ->where('phone_normalized', $normalizedPhone)
                    ->latest('id')
                    ->limit(10)
                    ->get(['id', 'first_name', 'last_name', 'phone', 'email']);

                if ($customers->isNotEmpty()) {
                    return response()->json(['data' => $customers]);
                }
            }

            $customers = Customer::query()
                ->when($query, function ($q) use ($query, $normalizedPhone) {
                    $digits = preg_replace('/\D+/', '', $query);
                    $like = '%' . $query . '%';

                    $q->where(function ($inner) use ($like, $digits, $normalizedPhone) {
                        $inner->where('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like)
                            ->orWhere('email', 'like', $like)
                            ->orWhere('phone', 'like', $like);

                        if ($digits !== '') {
                            // Частковий номер не може використати B-tree індекс через початковий %.
                            $inner->orWhere('phone_normalized', 'like', '%' . ($normalizedPhone ?: $digits) . '%');
                        }
                    });
                })
                ->latest('id')
                ->limit(10)
                ->get(['id', 'first_name', 'last_name', 'phone', 'email']);

            return response()->json(['data' => $customers]);
        }

        $perPage = min((int) $request->get('per_page', 30), 100);
        $customers = Customer::query()
            ->latest('id')
            ->paginate($perPage, ['id', 'first_name', 'last_name', 'phone', 'email']);

        return response()->json($customers);
    }

    /**
     * Детальна інформація про клієнта + останні замовлення (для quick-view оффканвасу).
     */
    public function show(Customer $customer): JsonResponse
    {
        $customer->loadCount('orders');

        $recentOrders = Order::query()
            ->where('customer_id', $customer->id)
            ->with([
                'delivery',
                'delivery.activeWarehouseStatus',
                'payment:id,order_id,method,prepay_amount,currency',
                'statusRef:id,code,name,icon,color',
                'items' => fn ($q) => $q->select('id', 'order_id', 'product_title', 'qty', 'price', 'product_id', 'size', 'color')
                    ->with([
                        'product:id,main_photo_path,category_id,color_id',
                        'product.category:id,name',
                        'product.color:id,name',
                    ]),
            ])
            ->withSum('items', 'total')
            ->latest('id')
            ->limit(10)
            ->get([
                'id',
                'order_number',
                'status',
                'status_id',
                'payment_status',
                'currency',
                'customer_id',
                'created_at',
            ]);

        $totalSpent = OrderItem::query()
            ->whereHas('order', fn ($q) => $q->where('customer_id', $customer->id))
            ->sum(DB::raw('total'));

        $lastOrderAt = Order::where('customer_id', $customer->id)->latest('id')->value('created_at');

        return response()->json([
            'data' => [
                'customer' => $customer,
                'metrics' => [
                    'orders_count' => $customer->orders_count,
                    'total_spent' => (float) $totalSpent,
                    'last_order_at' => $lastOrderAt,
                ],
                'recent_orders' => $recentOrders,
            ],
        ]);
    }
    /**
     * Оновлення контактних даних клієнта (ПІП, телефон, email).
     */
    public function update(Request $request, Customer $customer): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name'  => ['nullable', 'string', 'max:255'],
            'phone'      => ['nullable', 'string', 'max:20'],
            'email'      => ['nullable', 'email', 'max:255'],
        ]);

        // Оновлюємо лише передані поля, щоб частковий запит не затирав інші контакти.
        $payload = [];
        foreach (['first_name', 'last_name', 'email'] as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = $this->normalizeNullableString($validated[$field]);
            }
        }

        if (array_key_exists('phone', $validated)) {
            $phone = $this->normalizeNullablePhone($validated['phone']);

            $payload['phone'] = $phone;
            $payload['phone_normalized'] = PhoneNormalizer::normalize($phone);
        }

        $customer->update($payload);
        $customer->refresh();

        return response()->json([
            'status' => 'success',
            'message' => 'Дані клієнта оновлено',
            'data' => $customer,
        ]);
    }

    private function normalizeNullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function normalizeNullablePhone(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        return $digits !== '' ? $digits : null;
    }

    private function isNormalizedUkrainianPhone(?string $phone): bool
    {
        return $phone !== null && preg_match('/^380\d{9}$/', $phone) === 1;
    }
}
