<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
                'delivery',
                'delivery.activeWarehouseStatus',
                'payment:id,order_id,method,prepay_amount,currency',
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
            'avatar'     => 'nullable|image|max:4096',
        ]);

        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');
            $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');
            $relativePath = 'manual-avatars/' . now()->format('Y/m') . '/customer_' . $customer->id . '_' . uniqid() . '.' . $extension;

            Storage::disk('chat_uploads')->put($relativePath, file_get_contents($file->getRealPath()));
            @chmod(public_path('chat/' . $relativePath), 0644);

            $oldAvatar = trim((string) $customer->fb_profile_pic);
            if ($oldAvatar !== '' && str_starts_with($oldAvatar, 'chat/manual-avatars/')) {
                Storage::disk('chat_uploads')->delete(ltrim(str_replace('chat/', '', $oldAvatar), '/'));
            }

            $validated['fb_profile_pic'] = 'chat/' . $relativePath;
        }

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
