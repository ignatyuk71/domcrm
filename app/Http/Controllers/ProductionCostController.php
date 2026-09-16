<?php

namespace App\Http\Controllers;

use App\Http\Requests\CardboardCostBatchRequest;
use App\Http\Requests\FoamCostRequest;
use App\Http\Requests\FurCostRequest;
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

    public function cardboardData(Request $request, ProductionCostService $service): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);

        return response()->json($service->listing('cardboard'));
    }

    public function saveCardboard(CardboardCostBatchRequest $request, ProductionCostService $service, ?int $batch = null): JsonResponse
    {
        return response()->json($service->save($request->validated(), $request->user()->id, $batch, 'cardboard'), $batch ? 200 : 201);
    }

    public function foamData(Request $request, ProductionCostService $service): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);

        return response()->json($service->listing('foam'));
    }

    public function saveFoam(FoamCostRequest $request, ProductionCostService $service, ?int $batch = null): JsonResponse
    {
        return response()->json($service->save($request->validated(), $request->user()->id, $batch, 'foam'), $batch ? 200 : 201);
    }

    public function furData(Request $request, ProductionCostService $service): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);

        return response()->json($service->listing('fur'));
    }

    public function saveFur(FurCostRequest $request, ProductionCostService $service, ?int $batch = null): JsonResponse
    {
        return response()->json($service->save($request->validated(), $request->user()->id, $batch, 'fur'), $batch ? 200 : 201);
    }
}
