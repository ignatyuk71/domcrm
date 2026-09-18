<?php

namespace App\Http\Controllers;

use App\Services\WorkTime\WorkTimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkTimeController extends Controller
{
    private function month(Request $request): string
    {
        return $request->validate(['month' => ['required', 'date_format:Y-m', 'regex:/^(20\d{2}|2100)-(0[1-9]|1[0-2])$/']])['month'];
    }

    private function response(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request)
    {
        return response()->view('work-time.index', ['canManagePay' => $request->user()->isOwner()])
            ->header('Cache-Control', 'private, no-store');
    }

    public function data(Request $request, WorkTimeService $service): JsonResponse
    {
        return $this->response($service->listing($this->month($request)) + ['can_manage_pay' => $request->user()->isOwner()]);
    }

    public function createEmployee(Request $request, WorkTimeService $service): JsonResponse
    {
        $data = $request->validate(['request_key' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:100'], 'position' => ['nullable', 'string', 'max:100'], 'payment_type' => ['sometimes', 'required', 'in:hourly,piecework']]);

        return $this->response($service->createEmployee($data, $request->user()->id), 201);
    }

    public function updateEmployee(Request $request, WorkTimeService $service, int $employee): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'position' => ['nullable', 'string', 'max:100'],
            'payment_type' => ['sometimes', 'required', 'in:hourly,piecework'], 'archived' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:1']]);
        $data['version'] = (int) $data['version'];

        return $this->response($service->updateEmployee($employee, $data, $request->user()->id));
    }

    private function normalize(Request $request, array $fields): void
    {
        foreach ($fields as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => str_replace(',', '.', trim($request->input($field)))]);
            }
        }
    }

    public function saveEntry(Request $request, WorkTimeService $service, int $employee): JsonResponse
    {
        $this->normalize($request, ['hours']);
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'hours' => ['present', 'nullable', 'numeric', 'between:0,24', 'regex:/^\d{1,2}(\.\d{1,2})?$/'],
            'note' => ['nullable', 'string', 'max:500'], 'version' => ['required', 'integer', 'min:0']]);
        $data['version'] = (int) $data['version'];

        return $this->response($service->saveEntry($employee, $data, $request->user()->id));
    }

    public function payroll(Request $request, WorkTimeService $service, int $employee): JsonResponse
    {
        return $this->response($service->payroll($employee, $this->month($request)));
    }

    public function savePayroll(Request $request, WorkTimeService $service, int $employee): JsonResponse
    {
        $month = $this->month($request);
        $this->normalize($request, ['hourly_rate', 'bonus', 'paid']);
        $money = ['numeric', 'between:0,1000000', 'regex:/^\d{1,7}(\.\d{1,2})?$/'];
        $data = $request->validate(['hourly_rate' => ['present', 'nullable', ...$money],
            'bonus' => ['required', ...$money], 'paid' => ['required', ...$money], 'note' => ['nullable', 'string', 'max:500'],
            'version' => ['required', 'integer', 'min:0'], 'base_pay' => ['prohibited'], 'balance' => ['prohibited'], 'accrued' => ['prohibited']]);
        $data['version'] = (int) $data['version'];

        return $this->response($service->savePayroll($employee, $month, $data, $request->user()->id));
    }
}
