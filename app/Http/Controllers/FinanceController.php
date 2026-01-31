<?php

namespace App\Http\Controllers;

use App\Models\CheckboxSetting;
use App\Models\FiscalReceipt;
use App\Models\FiscalQueue;
use App\Services\CheckboxService;
use App\Services\FiscalQueueService;
use Illuminate\Http\Request;

class FinanceController extends Controller
{
    public function index()
    {
        $this->authorizeAccess();

        $settings = CheckboxSetting::current();
        $queueCount = FiscalQueue::query()
            ->where('status', FiscalQueue::STATUS_WAITING)
            ->count();

        return view('finance.index', [
            'settings' => $settings,
            'queueCount' => $queueCount,
        ]);
    }

    public function data(CheckboxService $checkbox)
    {
        $this->authorizeAccess();

        $settings = CheckboxSetting::current();
        $queueCount = FiscalQueue::query()
            ->where('status', FiscalQueue::STATUS_WAITING)
            ->count();

        $todayStart = now()->startOfDay();
        $todayEnd = now()->endOfDay();

        $todayReceipts = FiscalReceipt::query()
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get([
                'id',
                'order_id',
                'type',
                'status',
                'fiscal_code',
                'check_link',
                'total_amount',
                'created_at',
            ]);

        $hourlyCounts = array_fill(0, 24, 0);
        $hourlyRows = FiscalReceipt::query()
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->selectRaw('HOUR(created_at) as hour, COUNT(*) as count')
            ->groupBy('hour')
            ->get();

        foreach ($hourlyRows as $row) {
            $hour = (int) ($row->hour ?? 0);
            if ($hour >= 0 && $hour <= 23) {
                $hourlyCounts[$hour] = (int) $row->count;
            }
        }

        $hourlyAmounts = array_fill(0, 24, 0);
        $hourlyAmountRows = FiscalReceipt::query()
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->where('status', FiscalReceipt::STATUS_SUCCESS)
            ->where('type', FiscalReceipt::TYPE_SELL)
            ->selectRaw('HOUR(created_at) as hour, SUM(total_amount) as amount')
            ->groupBy('hour')
            ->get();

        foreach ($hourlyAmountRows as $row) {
            $hour = (int) ($row->hour ?? 0);
            if ($hour >= 0 && $hour <= 23) {
                $hourlyAmounts[$hour] = (int) $row->amount;
            }
        }

        $todayTotal = (int) FiscalReceipt::query()
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->where('status', FiscalReceipt::STATUS_SUCCESS)
            ->where('type', FiscalReceipt::TYPE_SELL)
            ->sum('total_amount');

        $shiftStatus = null;
        $connection = CheckboxSetting::resolveCheckboxConnection();
        $hasCredentials = !empty($connection['license_key'])
            && !empty($connection['login'])
            && !empty($connection['password']);

        if (($settings?->enabled ?? true) && $hasCredentials) {
            $shift = $checkbox->getCurrentShift();
            $shiftStatus = $shift['status'] ?? null;
        }

        return response()->json([
            'settings' => [
                'api_url' => $settings?->api_url,
                'login' => $settings?->login,
                'open_time' => $settings?->open_time,
                'close_time' => $settings?->close_time,
                'queue_process_time' => $settings?->queue_process_time,
                'enabled' => $settings?->enabled ?? true,
                'queue_enabled' => $settings?->queue_enabled ?? true,
                'has_license_key' => !empty($settings?->license_key),
                'has_password' => !empty($settings?->password),
            ],
            'shift_status' => $shiftStatus,
            'queue' => [
                'waiting' => $queueCount,
            ],
            'receipts' => [
                'date' => $todayStart->toDateString(),
                'daily_total' => $todayTotal,
                'hourly_counts' => $hourlyCounts,
                'hourly_amounts' => $hourlyAmounts,
                'items' => $todayReceipts->map(fn ($receipt) => [
                    'id' => $receipt->id,
                    'order_id' => $receipt->order_id,
                    'type' => $receipt->type,
                    'status' => $receipt->status,
                    'fiscal_code' => $receipt->fiscal_code,
                    'check_link' => $receipt->check_link,
                    'total_amount' => (int) $receipt->total_amount,
                    'created_at' => $receipt->created_at,
                ]),
            ],
        ]);
    }

    public function save(Request $request)
    {
        $this->authorizeAccess();

        $data = $request->validate([
            'api_url' => ['nullable', 'url'],
            'license_key' => ['nullable', 'string'],
            'login' => ['nullable', 'string'],
            'password' => ['nullable', 'string'],
            'open_time' => ['required', 'date_format:H:i'],
            'close_time' => ['required', 'date_format:H:i'],
            'queue_process_time' => ['required', 'date_format:H:i'],
            'enabled' => ['nullable', 'boolean'],
            'queue_enabled' => ['nullable', 'boolean'],
        ]);

        $settings = CheckboxSetting::current() ?? new CheckboxSetting();
        $settings->api_url = $data['api_url'] ?? null;
        $settings->login = $data['login'] ?? null;
        $settings->open_time = $data['open_time'];
        $settings->close_time = $data['close_time'];
        $settings->queue_process_time = $data['queue_process_time'];
        $settings->enabled = $request->boolean('enabled');
        $settings->queue_enabled = $request->boolean('queue_enabled');
        $settings->updated_by = $request->user()?->id;

        if ($request->filled('license_key')) {
            $settings->license_key = $data['license_key'];
        }

        if ($request->filled('password')) {
            $settings->password = $data['password'];
        }

        $settings->save();

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()
            ->route('finance.index')
            ->with('success', 'Налаштування збережено.');
    }

    public function test(CheckboxService $checkbox)
    {
        $this->authorizeAccess();

        $result = $checkbox->testConnection();
        $status = $result['ok'] ? 'success' : 'error';
        $message = $result['ok'] ? 'Підключення ок. ' . $result['message'] : 'Помилка: ' . $result['message'];

        if (request()->wantsJson()) {
            return response()->json([
                'ok' => $result['ok'] ?? false,
                'message' => $message,
                'shift' => $result['shift'] ?? null,
            ]);
        }

        return redirect()
            ->route('finance.index')
            ->with($status, $message);
    }

    public function openShift(CheckboxService $checkbox)
    {
        $this->authorizeAccess();

        $ok = $checkbox->openShift();
        $message = $ok ? 'Зміна відкрита.' : 'Не вдалося відкрити зміну.';

        if (request()->wantsJson()) {
            return response()->json(['ok' => $ok, 'message' => $message]);
        }

        return redirect()
            ->route('finance.index')
            ->with($ok ? 'success' : 'error', $message);
    }

    public function closeShift(CheckboxService $checkbox)
    {
        $this->authorizeAccess();

        $ok = $checkbox->closeShift();
        $message = $ok ? 'Зміна закрита.' : 'Не вдалося закрити зміну.';

        if (request()->wantsJson()) {
            return response()->json(['ok' => $ok, 'message' => $message]);
        }

        return redirect()
            ->route('finance.index')
            ->with($ok ? 'success' : 'error', $message);
    }

    public function processQueue(FiscalQueueService $queueService)
    {
        $this->authorizeAccess();

        $processed = $queueService->processAvailable();
        $message = $processed > 0
            ? "Черга оброблена. Успішно: {$processed}."
            : 'Черга порожня або поки не готова.';

        if (request()->wantsJson()) {
            return response()->json(['ok' => true, 'message' => $message, 'processed' => $processed]);
        }

        return redirect()
            ->route('finance.index')
            ->with('success', $message);
    }

    private function authorizeAccess(): void
    {
        $user = auth()->user();
        $adminEmail = config('app.finance_admin_email');
        $adminId = config('app.finance_admin_id');

        if ($adminEmail && $user?->email === $adminEmail) {
            return;
        }

        if ($adminId && (int) $adminId === (int) $user?->id) {
            return;
        }

        abort_unless($user && $user->id === 1, 403);
    }
}
