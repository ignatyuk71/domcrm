<?php

namespace App\Http\Controllers;

use App\Services\NovaPoshtaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $data = $this->np->searchCities($query);

        return response()->json([
            'data' => $data ?? []
        ]);
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

        $data = $this->np->getWarehouses($cityRef, $query, $limit);

        return response()->json([
            'data' => $data ?? []
        ]);
    }

    /**
     * Пошук вулиць (для кур'єрської доставки)
     */
    public function streets(Request $request): JsonResponse
    {
        $cityRef = $request->query('city_ref');
        $query = trim($request->query('q', ''));

        if (!$cityRef || mb_strlen($query) < 2) {
            return response()->json(['data' => []]);
        }

        $data = $this->np->searchStreets($cityRef, $query);

        return response()->json([
            'data' => $data ?? []
        ]);
    }
}