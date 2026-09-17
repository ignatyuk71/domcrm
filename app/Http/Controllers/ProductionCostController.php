<?php

namespace App\Http\Controllers;

use App\Http\Requests\CardboardCostBatchRequest;
use App\Http\Requests\FoamCostRequest;
use App\Http\Requests\FurCostRequest;
use App\Http\Requests\LaminateCostRequest;
use App\Http\Requests\SoleCostBatchRequest;
use App\Http\Requests\TapeCostRequest;
use App\Services\Costs\ProductionCostModels;
use App\Services\Costs\ProductionCostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductionCostController extends Controller
{
    public function models(ProductionCostModels $models): JsonResponse
    {
        return response()->json($models->catalog());
    }

    public function openModel(Request $request, ProductionCostModels $models): JsonResponse
    {
        $data = $request->validate(['category_id' => ['required', 'integer', 'min:1', 'exists:categories,id']]);

        return response()->json($models->openCategory((int) $data['category_id']));
    }

    private function modelId(Request $request): ?int
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'model_id' => ['sometimes', 'required', 'integer', 'min:1', 'exists:production_cost_models,id']]);

        return isset($data['model_id']) ? (int) $data['model_id'] : null;
    }

    public function index(Request $request)
    {
        $request->session()->put('analytics.last_tab', 'costs');

        return view('analytics.costs');
    }

    public function data(Request $request, ProductionCostService $service): JsonResponse
    {
        return response()->json($service->listing('soles', $this->modelId($request)));
    }

    public function save(SoleCostBatchRequest $request, ProductionCostService $service, ?int $batch = null): JsonResponse
    {
        return response()->json($service->save($request->validated(), $request->user()->id, $batch), $batch ? 200 : 201);
    }

    public function cardboardData(Request $request, ProductionCostService $service): JsonResponse
    {
        return response()->json($service->listing('cardboard', $this->modelId($request)));
    }

    public function saveCardboard(CardboardCostBatchRequest $request, ProductionCostService $service, ?int $batch = null): JsonResponse
    {
        return response()->json($service->save($request->validated(), $request->user()->id, $batch, 'cardboard'), $batch ? 200 : 201);
    }

    public function foamData(Request $request, ProductionCostService $service): JsonResponse
    {
        return response()->json($service->listing('foam', $this->modelId($request)));
    }

    public function saveFoam(FoamCostRequest $request, ProductionCostService $service, ?int $batch = null): JsonResponse
    {
        return response()->json($service->save($request->validated(), $request->user()->id, $batch, 'foam'), $batch ? 200 : 201);
    }

    public function furData(Request $request, ProductionCostService $service): JsonResponse
    {
        return response()->json($service->listing('fur', $this->modelId($request)));
    }

    public function saveFur(FurCostRequest $request, ProductionCostService $service, ?int $batch = null): JsonResponse
    {
        return response()->json($service->save($request->validated(), $request->user()->id, $batch, 'fur'), $batch ? 200 : 201);
    }

    public function laminateData(Request $request, ProductionCostService $service): JsonResponse
    {
        return response()->json($service->listing('laminate', $this->modelId($request)));
    }

    public function saveLaminate(LaminateCostRequest $request, ProductionCostService $service, ?int $batch = null): JsonResponse
    {
        return response()->json($service->save($request->validated(), $request->user()->id, $batch, 'laminate'), $batch ? 200 : 201);
    }

    public function tapeData(Request $request, ProductionCostService $service): JsonResponse
    {
        return response()->json($service->listing('tape', $this->modelId($request)));
    }

    public function saveTape(TapeCostRequest $request, ProductionCostService $service, ?int $batch = null): JsonResponse
    {
        return response()->json($service->save($request->validated(), $request->user()->id, $batch, 'tape'), $batch ? 200 : 201);
    }
}
