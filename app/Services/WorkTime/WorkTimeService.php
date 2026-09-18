<?php

namespace App\Services\WorkTime;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class WorkTimeService
{
    public function monthBounds(string $month): array
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $month.'-01');

        return [$date->toDateString(), $date->endOfMonth()->toDateString()];
    }

    public static function units(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '.', (string) $value)), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function decimal(?int $value): ?string
    {
        return $value === null ? null : number_format($value / 100, 2, '.', '');
    }

    public function employee(object $row): array
    {
        return ['id' => (int) $row->id, 'name' => $row->name, 'position' => $row->position,
            'payment_type' => $row->payment_type, 'archived_on' => $row->archived_on, 'version' => (int) $row->version];
    }

    public function entry(object $row): array
    {
        return ['employee_id' => (int) $row->employee_id, 'date' => $row->work_date,
            'hours' => self::decimal($row->hour_units === null ? null : (int) $row->hour_units),
            'note' => $row->note, 'version' => (int) $row->version, 'updated_at' => $row->updated_at,
            'updated_by' => $row->editor_name ?? null];
    }

    public function listing(string $month): array
    {
        [$start, $end] = $this->monthBounds($month);
        $employees = DB::table('work_employees')->where(function ($q) use ($start, $end) {
            $q->whereNull('archived_on')->orWhere('archived_on', '>=', $start)
                ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('work_time_entries')
                    ->whereColumn('employee_id', 'work_employees.id')->whereBetween('work_date', [$start, $end]));
        })->orderBy('id')->get()->map(fn ($row) => $this->employee($row))->all();
        $entries = DB::table('work_time_entries as entry')->leftJoin('users', 'users.id', '=', 'entry.updated_by')
            ->whereBetween('entry.work_date', [$start, $end])->select('entry.*', 'users.name as editor_name')
            ->orderBy('entry.work_date')->get()->map(fn ($row) => $this->entry($row))->all();

        // Зарплатні таблиці навмисно не читаються цим методом.
        return ['month' => $month, 'employees' => $employees, 'entries' => $entries];
    }

    private function audit(string $type, int $id, int $actor, ?object $before, object $after): void
    {
        DB::table('work_time_revisions')->insert(['subject_type' => $type, 'subject_id' => $id,
            'actor_id' => $actor, 'before' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
            'after' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    private function lockedEmployee(int $id): object
    {
        $employee = DB::table('work_employees')->where('id', $id)->lockForUpdate()->first();
        abort_unless($employee, 404);

        return $employee;
    }

    public function createEmployee(array $data, int $actor): array
    {
        return DB::transaction(function () use ($data, $actor) {
            // Унікальний ключ запобігає дублюванню після втрати відповіді сервера.
            DB::table('work_employees')->insertOrIgnore(['request_key' => $data['request_key'], 'name' => $data['name'],
                'position' => $data['position'] ?? null, 'payment_type' => $data['payment_type'] ?? 'hourly', 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('work_employees')->where('request_key', $data['request_key'])->lockForUpdate()->first();
            abort_unless($row && $row->name === $data['name'] && $row->position === ($data['position'] ?? null), 409, 'Цей запит уже використано з іншими даними.');
            abort_unless($row->payment_type === ($data['payment_type'] ?? 'hourly'), 409, 'Цей запит уже використано з іншим типом оплати.');
            if (! DB::table('work_time_revisions')->where('subject_type', 'employee')->where('subject_id', $row->id)->exists()) {
                $this->audit('employee', $row->id, $actor, null, $row);
            }

            return $this->employee($row);
        }, 3);
    }

    public function updateEmployee(int $id, array $data, int $actor): array
    {
        return DB::transaction(function () use ($id, $data, $actor) {
            $before = $this->lockedEmployee($id);
            abort_if(isset($data['payment_type']) && $data['payment_type'] !== $before->payment_type, 422, 'Тип оплати зберігається незмінним, щоб не змішувати історію обліку.');
            $values = ['name' => $data['name'], 'position' => $data['position'] ?? null,
                'archived_on' => $data['archived'] ? ($before->archived_on ?? now()->toDateString()) : null];
            $same = collect($values)->every(fn ($value, $key) => $value === $before->$key);
            if (! $same) {
                abort_unless((int) $before->version === $data['version'], 409, 'Працівника вже змінили. Оновіть сторінку.');
                DB::table('work_employees')->where('id', $id)->update($values + ['version' => $before->version + 1, 'updated_at' => now()]);
                $this->audit('employee', $id, $actor, $before, $this->lockedEmployee($id));
            }

            return $this->employee($this->lockedEmployee($id));
        }, 3);
    }

    public function deleteEmployee(int $id, int $version, int $actor): void
    {
        DB::transaction(function () use ($id, $version, $actor) {
            // Той самий замок використовують усі записи годин і нарахувань.
            $employee = DB::table('work_employees')->where('id', $id)->lockForUpdate()->first();
            if (! $employee) {
                return; // Повтор після втрати відповіді не створює помилки.
            }
            abort_unless((int) $employee->version === $version, 409, 'Працівника вже змінили. Оновіть табель перед видаленням.');
            $tables = ['entry' => 'work_time_entries', 'payroll' => 'work_payroll_months',
                'piecework' => 'work_piecework_entries', 'piecework_day' => 'work_piecework_days'];
            foreach ($tables as $type => $table) {
                // Прибираємо також попередні значення, що містять особисті й зарплатні дані.
                DB::table('work_time_revisions')->where('subject_type', $type)
                    ->whereIn('subject_id', DB::table($table)->select('id')->where('employee_id', $id))->delete();
                DB::table($table)->where('employee_id', $id)->delete();
            }
            DB::table('work_time_revisions')->where('subject_type', 'employee')->where('subject_id', $id)->delete();
            DB::table('work_employees')->where('id', $id)->delete();
            // Лише технічний факт видалення: без імені, годин чи сум.
            $this->audit('employee_deleted', $id, $actor, null, (object) ['deleted' => true]);
        }, 3);
    }

    public function saveEntry(int $id, array $data, int $actor): array
    {
        return DB::transaction(function () use ($id, $data, $actor) {
            $employee = $this->lockedEmployee($id);
            abort_unless($employee->payment_type === 'hourly', 422, 'Для цього працівника вносьте виконані роботи, а не години.');
            abort_if($employee->archived_on && $data['date'] > $employee->archived_on, 422, 'Після архівації нові робочі дні недоступні.');
            $query = DB::table('work_time_entries')->where('employee_id', $id)->where('work_date', $data['date']);
            $before = (clone $query)->first();
            $values = ['hour_units' => self::units($data['hours']), 'note' => $data['note'] ?? null];
            $same = $before && ($before->hour_units === null ? null : (int) $before->hour_units) === $values['hour_units'] && $before->note === $values['note'];
            if (! $same) {
                abort_unless((int) ($before->version ?? 0) === $data['version'], 409, 'Цей день уже змінили в іншому вікні. Оновіть табель перед повторним введенням.');
                $values += ['version' => ($before->version ?? 0) + 1, 'updated_by' => $actor, 'updated_at' => now()];
                if ($before) {
                    $query->update($values);
                } else {
                    DB::table('work_time_entries')->insert($values + ['employee_id' => $id, 'work_date' => $data['date'], 'created_at' => now()]);
                }
                $after = (clone $query)->first();
                $this->audit('entry', $after->id, $actor, $before, $after);
            }
            $row = (clone $query)->first();
            $row->editor_name = DB::table('users')->where('id', $row->updated_by)->value('name');

            return $this->entry($row);
        }, 3);
    }

    public function payroll(int $id, string $month): array
    {
        abort_unless(DB::table('work_employees')->where('id', $id)->exists(), 404);
        abort_unless(DB::table('work_employees')->where('id', $id)->value('payment_type') === 'hourly', 422, 'Нарахування цього працівника ведуться у відрядних роботах.');
        [$start, $end] = $this->monthBounds($month);
        $row = DB::table('work_payroll_months')->where('employee_id', $id)->where('month', $start)->first();
        $hours = (int) DB::table('work_time_entries')->where('employee_id', $id)->whereBetween('work_date', [$start, $end])->sum('hour_units');
        $rate = isset($row->hourly_rate_cents) ? (int) $row->hourly_rate_cents : null;
        $bonus = (int) ($row->bonus_cents ?? 0);
        $paid = (int) ($row->paid_cents ?? 0);
        // Округлення один раз до копійки, без накопичення похибок float.
        $base = $rate === null ? null : intdiv($hours * $rate + 50, 100);

        return ['employee_id' => $id, 'month' => $month, 'version' => (int) ($row->version ?? 0),
            'hours' => self::decimal($hours), 'hourly_rate' => self::decimal($rate),
            'bonus' => self::decimal($bonus), 'paid' => self::decimal($paid), 'note' => $row->note ?? null,
            'base_pay' => self::decimal($base), 'accrued' => self::decimal($base === null ? null : $base + $bonus),
            'balance' => self::decimal($base === null ? null : $base + $bonus - $paid)];
    }

    public function savePayroll(int $id, string $month, array $data, int $actor): array
    {
        return DB::transaction(function () use ($id, $month, $data, $actor) {
            abort_unless($this->lockedEmployee($id)->payment_type === 'hourly', 422, 'Використовуйте відрядні роботи.');
            [$start] = $this->monthBounds($month);
            $query = DB::table('work_payroll_months')->where('employee_id', $id)->where('month', $start);
            $before = (clone $query)->first();
            $values = ['hourly_rate_cents' => self::units($data['hourly_rate']),
                'bonus_cents' => self::units($data['bonus']), 'paid_cents' => self::units($data['paid']), 'note' => $data['note'] ?? null];
            $same = $before && collect($values)->every(fn ($value, $key) => $key === 'note'
                ? $value === $before->$key : ($before->$key === null ? null : (int) $before->$key) === $value);
            if (! $same) {
                abort_unless((int) ($before->version ?? 0) === $data['version'], 409, 'Нарахування вже змінили. Оновіть картку працівника.');
                $values += ['version' => ($before->version ?? 0) + 1, 'updated_at' => now()];
                if ($before) {
                    $query->update($values);
                } else {
                    DB::table('work_payroll_months')->insert($values + ['employee_id' => $id, 'month' => $start, 'created_at' => now()]);
                }
                $after = (clone $query)->first();
                $this->audit('payroll', $after->id, $actor, $before, $after);
            }

            return $this->payroll($id, $month);
        }, 3);
    }
}
