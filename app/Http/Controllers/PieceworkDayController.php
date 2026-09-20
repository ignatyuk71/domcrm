<?php

namespace App\Http\Controllers;

use App\Services\WorkTime\PieceworkDayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PieceworkDayController extends Controller
{
    public function index(Request $request, PieceworkDayService $service): JsonResponse
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m', 'regex:/^(20\d{2}|2100)-(0[1-9]|1[0-2])$/']]);

        return response()->json($service->listing($data['month']))->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, PieceworkDayService $service, int $employee): JsonResponse
    {
        if (is_string($request->input('amount'))) {
            $request->merge(['amount' => str_replace(',', '.', trim($request->input('amount')))]);
        }
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'amount' => ['present', 'nullable', 'numeric', 'between:0,1000000000', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'version' => ['required', 'integer', 'min:0'], 'paid' => ['prohibited']]);

        return response()->json($service->save($employee, $data, $request->user()->id))->header('Cache-Control', 'private, no-store');
    }
}
