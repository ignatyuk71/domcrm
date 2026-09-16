<?php

namespace App\Http\Controllers;

use App\Http\Requests\SoleCostBatchRequest;
use App\Services\Costs\ProductionCostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductionCostController extends Controller
{
    public function index(Request $request)
    {
        $request->session()->put('analytics.last_tab', 'costs');

        return view('analytics.costs');
    }

    public function data(Request $request, ProductionCostService $service): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);

        return response()->json($service->listing());
    }

    public function save(SoleCostBatchRequest $request, ProductionCostService $service, ?int $batch = null): JsonResponse
    {
        return response()->json($service->save($request->validated(), $request->user()->id, $batch), $batch ? 200 : 201);
    }
}
