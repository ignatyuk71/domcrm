<?php

namespace App\Http\Controllers;

use App\Services\NovaPoshtaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NovaPoshtaController extends Controller
{
    /**
     * Впроваджуємо сервіс через конструктор
     */
    public function __construct(private NovaPoshtaService $np)
    {
    }

    /**
     * Пошук міст
     */
    public function cities(Request $request): JsonResponse
    {
        $query = trim($request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['data' => []]);
        }

        if (!$this->np->getApiKey()) {
            return response()->json(['data' => [], 'error' => 'Нова Пошта не налаштована']);
        }

        try {
            return response()->json(['data' => $this->np->searchCities($query)]);
        } catch (\Throwable $e) {
            Log::error('NovaPoshta cities search failed', ['error' => $e->getMessage()]);
            return response()->json(['data' => [], 'error' => 'Помилка Нової Пошти, спробуйте пізніше']);
        }
    }

    /**
     * Пошук відділень або поштоматів
     */
    public function warehouses(Request $request): JsonResponse
    {
        $cityRef = $request->query('city_ref');
        $query = trim($request->query('q', ''));
        $limit = (int) $request->query('limit', 50);

        if (!$cityRef) {
            return response()->json(['data' => []]);
        }

        if (!$this->np->getApiKey()) {
            return response()->json(['data' => [], 'error' => 'Нова Пошта не налаштована']);
        }

        try {
            return response()->json(['data' => $this->np->getWarehouses($cityRef, $query, $limit)]);
        } catch (\Throwable $e) {
            Log::error('NovaPoshta warehouses search failed', ['error' => $e->getMessage()]);
            return response()->json(['data' => [], 'error' => 'Помилка Нової Пошти, спробуйте пізніше']);
        }
    }

    /**
     * Пошук вулиць (для кур'єрської доставки)
     */
    public function streets(Request $request): JsonResponse
    {
        $cityRef = $request->query('city_ref');
        $query = trim($request->query('q', ''));
        $limit = (int) $request->query('limit', 25);
        $settlementRef = $request->query('settlement_ref');

        if ((!$cityRef && !$settlementRef) || mb_strlen($query) < 2) {
            return response()->json(['data' => []]);
        }

        if (!$this->np->getApiKey()) {
            return response()->json(['data' => [], 'error' => 'Нова Пошта не налаштована']);
        }

        try {
            $data = $settlementRef
                ? $this->np->searchSettlementStreets($settlementRef, $query, $limit)
                : $this->np->searchStreets($cityRef ?? '', $query, $limit);

            return response()->json(['data' => $data]);
        } catch (\Throwable $e) {
            Log::error('NovaPoshta streets search failed', ['error' => $e->getMessage()]);
            return response()->json(['data' => [], 'error' => 'Помилка Нової Пошти, спробуйте пізніше']);
        }
    }
}
