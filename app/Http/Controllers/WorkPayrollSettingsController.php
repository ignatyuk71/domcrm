<?php

namespace App\Http\Controllers;

use App\Services\WorkTime\PayrollReportService;
use Illuminate\Http\Request;

class WorkPayrollSettingsController extends Controller
{
    private function month(Request $request): string
    {
        return $request->validate(['month' => ['required', 'date_format:Y-m', 'regex:/^(20\d{2}|2100)-(0[1-9]|1[0-2])$/']])['month'];
    }

    public function index()
    {
        return response()->view('settings.work-payroll')->header('Cache-Control', 'private, no-store');
    }

    public function employees(PayrollReportService $service)
    {
        return response()->json(['employees' => $service->employees()])->header('Cache-Control', 'private, no-store');
    }

    public function report(Request $request, PayrollReportService $service)
    {
        return response()->json($service->report($this->month($request)))->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, PayrollReportService $service, int $employee)
    {
        $month = $this->month($request);
        foreach (['rate', 'bonus', 'expenses', 'adjustment', 'paid'] as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => str_replace(',', '.', trim($request->input($field)))]);
            }
        }
        $money = ['numeric', 'between:0,1000000', 'regex:/^\d{1,7}(\.\d{1,2})?$/'];
        $data = $request->validate(['rate_mode' => ['required', 'in:hourly,daily,piecework'], 'rate' => ['present', 'nullable', ...$money],
            'bonus' => ['required', ...$money], 'expenses' => ['required', ...$money], 'paid' => ['required', ...$money],
            'adjustment' => ['required', 'numeric', 'between:-1000000,1000000', 'regex:/^-?\d{1,7}(\.\d{1,2})?$/'],
            'adjustment_reason' => ['nullable', 'string', 'max:500'], 'note' => ['nullable', 'string', 'max:500'],
            'version' => ['required', 'integer', 'min:0'], 'daily_hours' => ['prohibited'], 'base_pay' => ['prohibited'], 'salary' => ['prohibited'], 'accrued' => ['prohibited'], 'balance' => ['prohibited']]);
        if ((float) $data['adjustment'] !== 0.0 && trim($data['adjustment_reason'] ?? '') === '') {
            throw \Illuminate\Validation\ValidationException::withMessages(['adjustment_reason' => 'Поясніть причину коригування.']);
        }

        return response()->json($service->save($employee, $month, $data, $request->user()->id))->header('Cache-Control', 'private, no-store');
    }
}
