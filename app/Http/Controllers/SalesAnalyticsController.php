<?php

namespace App\Http\Controllers;

use App\Http\Requests\SalesAnalyticsRequest;
use App\Services\Analytics\SalesAnalyticsService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesAnalyticsController extends Controller
{
    public function index()
    {
        return view('analytics.sales');
    }

    public function data(SalesAnalyticsRequest $request, SalesAnalyticsService $analytics): JsonResponse
    {
        return response()->json($analytics->report($request->validated()));
    }

    public function export(SalesAnalyticsRequest $request, SalesAnalyticsService $analytics): StreamedResponse
    {
        $filters = $analytics->normalizeFilters($request->validated());
        $filename = "sales-analytics-{$filters['date_from']}-{$filters['date_to']}.csv";

        return response()->streamDownload(function () use ($analytics, $filters) {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Дата', 'Замовлення', 'Клієнт', 'Тип продажу', 'Код джерела', 'Джерело',
                'Менеджер', 'Статус', 'Оплата', 'Одиниць', 'Виторг', 'Собівартість',
                'Валовий прибуток', 'Маржа, %', 'Валюта',
            ], ';', '"', '');

            foreach ($analytics->exportRows($filters) as $row) {
                fputcsv($output, [
                    $this->csvCell($row->created_at),
                    $this->csvCell($row->order_number),
                    $this->csvCell(trim(($row->customer_first_name ?? '').' '.($row->customer_last_name ?? ''))),
                    $this->csvCell($row->sale_type === 'wholesale' ? 'Опт' : 'Роздріб'),
                    $this->csvCell($row->source_code),
                    $this->csvCell($row->source_name),
                    $this->csvCell($row->manager_name),
                    $this->csvCell($row->status_name),
                    $this->csvCell($row->payment_status),
                    (int) $row->units,
                    (float) $row->revenue,
                    (float) $row->cogs,
                    (float) $row->profit,
                    $row->costed_revenue > 0 ? round(($row->profit / $row->costed_revenue) * 100, 2) : null,
                    $this->csvCell($row->currency),
                ], ';', '"', '');
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Захист від виконання формул Excel у полях із користувацькими даними. */
    private function csvCell(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return preg_match('/^[=+\-@]/u', ltrim($value)) ? "'{$value}" : $value;
    }
}
