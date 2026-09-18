<?php

namespace App\Services\WorkTime;

use Illuminate\Support\Facades\DB;

class PieceworkDayService
{
    private function entry(object $row): array
    {
        return ['employee_id' => (int) $row->employee_id, 'date' => $row->work_date,
            'amount' => WorkTimeService::decimal($row->amount_cents === null ? null : (int) $row->amount_cents),
            'version' => (int) $row->version];
    }

    public function listing(string $month): array
    {
        [$start, $end] = app(WorkTimeService::class)->monthBounds($month);
        $employees = DB::table('work_employees')->where('payment_type', 'piecework')->where(function ($q) use ($start, $end) {
            $q->whereNull('archived_on')->orWhere('archived_on', '>=', $start);
            foreach (['work_piecework_entries', 'work_piecework_days'] as $table) {
                $q->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from($table)
                    ->whereColumn('employee_id', 'work_employees.id')->whereBetween('work_date', [$start, $end]));
            }
        })->orderBy('id')->get()->map(fn ($row) => app(WorkTimeService::class)->employee($row))->all();
        // Попередні роботи показуємо сумою за день, не змінюючи їхні записи чи виплати.
        $legacy = DB::table('work_piecework_entries')->whereBetween('work_date', [$start, $end])
            ->selectRaw('employee_id, work_date, SUM(total_cents) as amount_cents, SUM(version) as version')
            ->groupBy('employee_id', 'work_date')->get();
        $entries = [];
        foreach ($legacy as $row) {
            $entries[$row->employee_id.'|'.$row->work_date] = $this->entry($row);
        }
        foreach (DB::table('work_piecework_days')->whereBetween('work_date', [$start, $end])->get() as $row) {
            // Клітинка — повний підсумок дня, а не доплата до старих записів.
            $entries[$row->employee_id.'|'.$row->work_date] = $this->entry($row);
        }

        return ['month' => $month, 'employees' => $employees, 'entries' => array_values($entries)];
    }

    public function save(int $employeeId, array $data, int $actor): array
    {
        return DB::transaction(function () use ($employeeId, $data, $actor) {
            $employee = DB::table('work_employees')->where('id', $employeeId)->lockForUpdate()->first();
            abort_unless($employee, 404);
            abort_unless($employee->payment_type === 'piecework', 422, 'Для погодинного працівника заповнюйте години.');
            abort_if($employee->archived_on && $data['date'] > $employee->archived_on, 422, 'Після архівації нові робочі дні недоступні.');
            $query = DB::table('work_piecework_days')->where('employee_id', $employeeId)->where('work_date', $data['date']);
            $before = (clone $query)->first();
            $amount = WorkTimeService::units($data['amount']);
            if ($before && ($before->amount_cents === null ? null : (int) $before->amount_cents) === $amount) {
                return $this->entry($before);
            }
            $legacy = DB::table('work_piecework_entries')->where('employee_id', $employeeId)->where('work_date', $data['date'])
                ->selectRaw('SUM(total_cents) as amount_cents, COALESCE(SUM(version), 0) as version')->first();
            $version = (int) ($before->version ?? $legacy->version);
            abort_unless($version === (int) $data['version'], 409, 'Суму за цей день уже змінили. Оновіть таблицю.');
            $values = ['amount_cents' => $amount, 'version' => $version + 1, 'updated_by' => $actor, 'updated_at' => now()];
            if ($before) {
                $query->update($values);
            } else {
                DB::table('work_piecework_days')->insert($values + ['employee_id' => $employeeId, 'work_date' => $data['date'], 'created_at' => now()]);
            }
            $after = (clone $query)->first();
            $auditBefore = $before ?? ($legacy->version ? (object) ['employee_id' => $employeeId, 'work_date' => $data['date'], 'amount_cents' => $legacy->amount_cents, 'version' => $legacy->version, 'source' => 'legacy'] : null);
            DB::table('work_time_revisions')->insert(['subject_type' => 'piecework_day', 'subject_id' => $after->id, 'actor_id' => $actor,
                'before' => $auditBefore ? json_encode($auditBefore, JSON_THROW_ON_ERROR) : null,
                'after' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $this->entry($after);
        }, 3);
    }
}
