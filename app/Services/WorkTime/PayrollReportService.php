<?php

namespace App\Services\WorkTime;

use Illuminate\Support\Facades\DB;

class PayrollReportService
{
    public function employees(): array
    {
        return DB::table('work_employees')->orderBy('name')->orderBy('id')->get()
            ->map(fn ($row) => app(WorkTimeService::class)->employee($row))->all();
    }

    public function report(string $month, ?int $onlyEmployee = null): array
    {
        // Один знімок БД: паралельне збереження табеля не змішує частини звіту.
        return DB::transaction(fn () => $this->buildReport($month, $onlyEmployee));
    }

    private function buildReport(string $month, ?int $onlyEmployee): array
    {
        [$start, $end] = app(WorkTimeService::class)->monthBounds($month);
        $team = DB::table('work_employees');
        if ($onlyEmployee !== null) {
            $team->where('id', $onlyEmployee);
        } else {
            $team->where(function ($q) use ($start, $end) {
                $q->whereNull('archived_on')->orWhere('archived_on', '>=', $start);
                foreach (['work_time_entries', 'work_piecework_days', 'work_piecework_entries', 'work_payroll_months'] as $table) {
                    $q->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from($table)
                        ->whereColumn('employee_id', 'work_employees.id')
                        ->whereBetween($table === 'work_payroll_months' ? 'month' : 'work_date', [$start, $end]));
                }
            });
        }
        $employees = $team->orderBy('name')->orderBy('id')->get();
        $ids = $employees->pluck('id');
        // Фіксована кількість запитів на місяць, без окремого запиту для кожного працівника.
        $payrolls = DB::table('work_payroll_months')->whereIn('employee_id', $ids)->where('month', $start)->get()->keyBy('employee_id');
        $hours = DB::table('work_time_entries')->whereIn('employee_id', $ids)->whereBetween('work_date', [$start, $end])
            ->selectRaw('employee_id, COALESCE(SUM(hour_units), 0) as hours, SUM(CASE WHEN hour_units > 0 THEN 1 ELSE 0 END) as days')
            ->groupBy('employee_id')->get()->keyBy('employee_id');
        $legacy = DB::table('work_piecework_entries')->whereIn('employee_id', $ids)->whereBetween('work_date', [$start, $end])
            ->selectRaw('employee_id, work_date, SUM(total_cents) as amount, SUM(paid_cents) as paid')->groupBy('employee_id', 'work_date')->get();
        $amounts = $paidLegacy = [];
        foreach ($legacy as $row) {
            $amounts[$row->employee_id][$row->work_date] = (int) $row->amount;
            $paidLegacy[$row->employee_id] = ($paidLegacy[$row->employee_id] ?? 0) + (int) $row->paid;
        }
        foreach (DB::table('work_piecework_days')->whereIn('employee_id', $ids)->whereBetween('work_date', [$start, $end])->get() as $row) {
            // Навіть порожня нова клітинка заміщає старий підсумок, а не додається до нього.
            $amounts[$row->employee_id][$row->work_date] = (int) ($row->amount_cents ?? 0);
        }
        $rows = $employees->map(function ($employee) use ($month, $payrolls, $hours, $amounts, $paidLegacy) {
            $p = $payrolls->get($employee->id);
            $h = (int) ($hours->get($employee->id)->hours ?? 0);
            $isHourly = $employee->payment_type === 'hourly';
            $mode = $isHourly ? ($p->rate_mode ?? 'hourly') : 'piecework';
            $hourlyRate = isset($p->hourly_rate_cents) ? (int) $p->hourly_rate_cents : null;
            $dailyRate = isset($p->daily_rate_cents) ? (int) $p->daily_rate_cents : null;
            $rate = $mode === 'daily' ? $dailyRate : $hourlyRate;
            // 8 год = повна денна ставка; неповний день оплачується пропорційно, округлення один раз.
            $denominator = $mode === 'daily' ? 800 : 100;
            $base = $isHourly ? ($rate === null ? null : intdiv($h * $rate + intdiv($denominator, 2), $denominator))
                : array_sum($amounts[$employee->id] ?? []);
            $bonus = (int) ($p->bonus_cents ?? 0);
            $expense = (int) ($p->expense_cents ?? 0);
            $adjustment = (int) ($p->adjustment_cents ?? 0);
            $paid = $p ? (int) $p->paid_cents : ($paidLegacy[$employee->id] ?? 0);
            $salary = $base === null ? null : $base + $adjustment;
            $accrued = $salary === null ? null : $salary + $bonus + $expense;

            return ['employee' => app(WorkTimeService::class)->employee($employee), 'employee_id' => (int) $employee->id,
                'month' => $month, 'version' => (int) ($p->version ?? 0), 'rate_mode' => $mode,
                'hours' => WorkTimeService::decimal($h), 'days' => $isHourly ? (int) ($hours->get($employee->id)->days ?? 0) : null,
                'hourly_rate' => WorkTimeService::decimal($hourlyRate), 'daily_rate' => WorkTimeService::decimal($dailyRate),
                'base_pay' => WorkTimeService::decimal($base), 'adjustment' => WorkTimeService::decimal($adjustment),
                'adjustment_reason' => $p->adjustment_reason ?? null, 'salary' => WorkTimeService::decimal($salary),
                'bonus' => WorkTimeService::decimal($bonus), 'expenses' => WorkTimeService::decimal($expense),
                'accrued' => WorkTimeService::decimal($accrued), 'paid' => WorkTimeService::decimal($paid),
                'balance' => WorkTimeService::decimal($accrued === null ? null : $accrued - $paid), 'note' => $p->note ?? null];
        })->all();
        $totals = [];
        foreach (['salary', 'bonus', 'expenses', 'accrued', 'paid', 'balance'] as $field) {
            $totals[$field] = WorkTimeService::decimal(array_sum(array_map(fn ($row) => self::signedUnits($row[$field] ?? '0'), $rows)));
        }

        return ['month' => $month, 'rows' => $rows, 'totals' => $totals,
            'incomplete_count' => count(array_filter($rows, fn ($row) => $row['accrued'] === null))];
    }

    public function forEmployee(int $id, string $month): array
    {
        $rows = $this->report($month, $id)['rows'];
        abort_unless($rows, 404);

        return $rows[0];
    }

    private static function signedUnits(mixed $value): int
    {
        $text = (string) $value;

        return str_starts_with($text, '-') ? -WorkTimeService::units(substr($text, 1)) : (int) WorkTimeService::units($text);
    }

    public function save(int $id, string $month, array $data, int $actor): array
    {
        return DB::transaction(function () use ($id, $month, $data, $actor) {
            $employee = DB::table('work_employees')->where('id', $id)->lockForUpdate()->first();
            abort_unless($employee, 404);
            abort_unless($employee->payment_type === 'hourly' ? in_array($data['rate_mode'], ['hourly', 'daily'], true)
                : $data['rate_mode'] === 'piecework', 422, 'Спосіб нарахування не відповідає типу працівника.');
            [$start] = app(WorkTimeService::class)->monthBounds($month);
            $query = DB::table('work_payroll_months')->where('employee_id', $id)->where('month', $start);
            $before = (clone $query)->first();
            $values = ['rate_mode' => $data['rate_mode'],
                'hourly_rate_cents' => $data['rate_mode'] === 'hourly' ? WorkTimeService::units($data['rate']) : null,
                'daily_rate_cents' => $data['rate_mode'] === 'daily' ? WorkTimeService::units($data['rate']) : null,
                'bonus_cents' => WorkTimeService::units($data['bonus']), 'expense_cents' => WorkTimeService::units($data['expenses']),
                'adjustment_cents' => self::signedUnits($data['adjustment']), 'adjustment_reason' => $data['adjustment_reason'] ?? null,
                'paid_cents' => WorkTimeService::units($data['paid']), 'note' => $data['note'] ?? null];
            $same = $before && collect($values)->every(fn ($value, $key) => str_ends_with($key, '_cents')
                ? ($before->$key === null ? null : (int) $before->$key) === $value : $before->$key === $value);
            if (! $same) {
                abort_unless((int) ($before->version ?? 0) === (int) $data['version'], 409, 'Нарахування вже змінили. Закрийте картку й оновіть звіт.');
                $values += ['version' => ($before->version ?? 0) + 1, 'updated_at' => now()];
                if ($before) {
                    $query->update($values);
                } else {
                    DB::table('work_payroll_months')->insert($values + ['employee_id' => $id, 'month' => $start, 'created_at' => now()]);
                }
                $after = (clone $query)->first();
                DB::table('work_time_revisions')->insert(['subject_type' => 'payroll', 'subject_id' => $after->id,
                    'actor_id' => $actor, 'before' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
                    'after' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now()]);
            }

            return $this->forEmployee($id, $month);
        }, 3);
    }
}
