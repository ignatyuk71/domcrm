<?php

namespace App\Http\Controllers;

use App\Http\Requests\SoleInventoryBatchRequest;
use App\Http\Requests\SoleInventoryMovementRequest;
use App\Http\Requests\SoleInventoryPlanRequest;
use App\Services\Inventory\SoleInventoryReport;
use App\Services\Inventory\SoleInventoryService;
use Illuminate\Http\JsonResponse;

class SoleInventoryController extends Controller
{
    public function index()
    {
        return view('inventory.soles');
    }

    public function data(SoleInventoryReport $report): JsonResponse
    {
        return response()->json($report->build());
    }

    public function savePlan(int $category, SoleInventoryPlanRequest $request, SoleInventoryService $service): JsonResponse
    {
        $service->savePlan($category, $request->validated(), $request->user()->id);

        return response()->json(['saved' => true]);
    }

    public function addMovement(int $category, SoleInventoryMovementRequest $request, SoleInventoryService $service): JsonResponse
    {
        $id = $service->addMovement($category, $request->validated(), $request->user()->id);

        return response()->json(['id' => $id], 201);
    }

    public function saveBatch(int $category, SoleInventoryBatchRequest $request, SoleInventoryService $service, ?int $batch = null): JsonResponse
    {
        $id = $service->saveBatch($category, $request->validated(), $request->user()->id, $batch);

        return response()->json(['id' => $id], $batch ? 200 : 201);
    }
}
