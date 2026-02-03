<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
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
            $customers = Customer::query()
                ->when($query, function ($q) use ($query) {
                    $normalizedPhone = preg_replace('/\D+/', '', $query);
                    $like = '%' . $query . '%';

                    $q->where(function ($inner) use ($like, $normalizedPhone) {
                        $inner->where('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like)
                            ->orWhere('email', 'like', $like)
                            ->orWhere('phone', 'like', $like);

                        if ($normalizedPhone !== '') {
                            // Спрощене нормалізоване порівняння номера без спеціальних символів
                            $inner->orWhereRaw(
                                'REPLACE(REPLACE(REPLACE(REPLACE(phone, "+", ""), "-", ""), " ", ""), "(", "") LIKE ?',
                                ['%' . $normalizedPhone . '%']
                            );
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
                'delivery:id,order_id,ttn,delivery_status_label,delivery_status_code,delivery_status_updated_at,city_name,warehouse_name',
                'delivery.activeWarehouseStatus:order_delivery_id,entered_at,exited_at',
                'statusRef:id,code,name,icon,color',
                'items' => fn ($q) => $q->select('id', 'order_id', 'product_title', 'qty', 'price', 'product_id')
                    ->with('product:id,main_photo_path'),
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
        // 1. Валідація вхідних даних
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name'  => 'required|string|max:255',
            'phone'      => 'required|string|max:20',
            'email'      => 'nullable|email|max:255',
        ]);

        // 2. Оновлення моделі
        $customer->update($validated);

        // 3. Повернення успішної відповіді
        return response()->json([
            'status' => 'success',
            'message' => 'Дані клієнта оновлено',
            'data' => $customer
        ]);
    }
}
