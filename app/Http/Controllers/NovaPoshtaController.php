<?php

namespace App\Http\Controllers;

use App\Services\NovaPoshtaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NovaPoshtaController extends Controller
{
    public function __construct(private NovaPoshtaService $np)
    {
    }

    /**
     * Автопідказка міст (searchSettlements).
     */
    public function cities(Request $request): JsonResponse
    {
        $query = trim((string) $request->get('q', ''));
        if (strlen($query) < 2) {
            return response()->json(['data' => []]);
        }

        $data = $this->np->searchCities($query);
        return response()->json(['data' => $data]);
    }

    /**
     * Автопідказка відділень/поштоматів за містом.
     */
    public function warehouses(Request $request): JsonResponse
    {
        $cityRef = $request->string('city_ref')->toString();
        $query = trim((string) $request->get('q', ''));
        $limit = (int) $request->get('limit', 50);
        if (!$cityRef) {
            return response()->json(['data' => []]);
        }

        $data = $this->np->getWarehouses($cityRef, $query, $limit);
        return response()->json(['data' => $data]);
    }

    /**
     * Автопідказка вулиць (для кур'єрської доставки).
     */
    public function streets(Request $request): JsonResponse
    {
        $cityRef = $request->string('city_ref')->toString();
        $query = trim((string) $request->get('q', ''));
        if (!$cityRef || strlen($query) < 2) {
            return response()->json(['data' => []]);
        }

        $data = $this->np->searchStreets($cityRef, $query);
        return response()->json(['data' => $data]);
    }
}
