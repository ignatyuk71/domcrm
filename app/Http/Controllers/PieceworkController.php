<?php

namespace App\Http\Controllers;

use App\Services\WorkTime\PieceworkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PieceworkController extends Controller
{
    public function index(Request $request, PieceworkService $service): JsonResponse
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m', 'regex:/^(20\d{2}|2100)-(0[1-9]|1[0-2])$/'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);

        return response()->json($service->listing($data['month'], (int) ($data['page'] ?? 1)))->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, PieceworkService $service, ?int $entry = null): JsonResponse
    {
        foreach (['unit_rate', 'agreed_total', 'paid'] as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => str_replace(',', '.', trim($request->input($field)))]);
            }
        }
        $money = ['numeric', 'between:0,1000000', 'regex:/^\d{1,7}(\.\d{1,2})?$/'];
        $data = $request->validate(['request_key' => [$entry ? 'sometimes' : 'required', 'uuid'],
            'employee_id' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'description' => ['required', 'string', 'max:200'], 'quantity' => ['required', 'integer', 'between:1,1000000'],
            'unit' => ['required', 'in:piece,pair'], 'pricing_mode' => ['required', 'in:unit,fixed'],
            'unit_rate' => ['exclude_unless:pricing_mode,unit', 'required', ...$money],
            'agreed_total' => ['exclude_unless:pricing_mode,fixed', 'required', ...$money],
            'paid' => ['required', ...$money], 'note' => ['nullable', 'string', 'max:500'],
            'version' => [$entry ? 'required' : 'sometimes', 'integer', 'min:1'],
            'total' => ['prohibited'], 'balance' => ['prohibited']]);

        return response()->json($service->save($entry, $data, $request->user()->id), $entry ? 200 : 201)
            ->header('Cache-Control', 'private, no-store');
    }
}
