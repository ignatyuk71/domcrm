<?php

namespace App\Services\WorkTime;

use Illuminate\Support\Facades\DB;

class PieceworkService
{
    public function entry(object $row): array
    {
        return ['id' => (int) $row->id, 'employee_id' => (int) $row->employee_id,
            'date' => $row->work_date, 'description' => $row->description,
            'quantity' => (int) $row->quantity, 'unit' => $row->unit, 'pricing_mode' => $row->pricing_mode,
            'unit_rate' => WorkTimeService::decimal($row->unit_rate_cents === null ? null : (int) $row->unit_rate_cents),
            'total' => WorkTimeService::decimal((int) $row->total_cents),
            'paid' => WorkTimeService::decimal((int) $row->paid_cents),
            'balance' => WorkTimeService::decimal($row->total_cents - $row->paid_cents),
            'note' => $row->note, 'version' => (int) $row->version];
    }

    public function listing(string $month, int $page): array
    {
        [$start, $end] = app(WorkTimeService::class)->monthBounds($month);
        $query = DB::table('work_piecework_entries')->whereBetween('work_date', [$start, $end]);
        $summary = (clone $query)->selectRaw('COUNT(*) as count, COALESCE(SUM(total_cents), 0) as total, COALESCE(SUM(paid_cents), 0) as paid')->first();
        $employees = DB::table('work_employees')->where('payment_type', 'piecework')->where(function ($q) use ($start, $end) {
            $q->whereNull('archived_on')->orWhere('archived_on', '>=', $start)
                ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('work_piecework_entries')
                    ->whereColumn('employee_id', 'work_employees.id')->whereBetween('work_date', [$start, $end]));
        })->orderBy('name')->get()->map(fn ($row) => app(WorkTimeService::class)->employee($row))->all();

        return ['month' => $month, 'employees' => $employees,
            'entries' => $query->orderByDesc('work_date')->orderByDesc('id')->forPage($page, 30)->get()->map(fn ($row) => $this->entry($row))->all(),
            'page' => $page, 'last_page' => max(1, (int) ceil($summary->count / 30)), 'count' => (int) $summary->count,
            'summary' => ['total' => WorkTimeService::decimal((int) $summary->total), 'paid' => WorkTimeService::decimal((int) $summary->paid),
                'balance' => WorkTimeService::decimal($summary->total - $summary->paid)]];
    }

    public function save(?int $id, array $data, int $actor): array
    {
        return DB::transaction(function () use ($id, $data, $actor) {
            // Один порядок блокувань для створення, редагування та архівації працівника.
            $employee = DB::table('work_employees')->where('id', $data['employee_id'])->lockForUpdate()->first();
            abort_unless($employee, 404);
            abort_unless($employee->payment_type === 'piecework', 422, 'Оберіть працівника з оплатою за виконану роботу.');
            abort_if($employee->archived_on && $data['date'] > $employee->archived_on, 422, 'Дата роботи має бути не пізнішою за дату архівації.');
            $before = $id ? DB::table('work_piecework_entries')->where('id', $id)->lockForUpdate()->first() : null;
            abort_if($id && ! $before, 404);
            abort_if($before && (int) $before->employee_id !== (int) $data['employee_id'], 422, 'Не можна перенести збережену роботу іншому працівнику.');
            abort_if(DB::table('work_piecework_days')->where('employee_id', $data['employee_id'])
                ->whereIn('work_date', array_unique([$data['date'], $before->work_date ?? $data['date']]))->exists(),
                409, 'Цей день уже ведеться у новій таблиці сум. Оновіть сторінку та редагуйте суму в клітинці.');
            $rate = $data['pricing_mode'] === 'unit' ? WorkTimeService::units($data['unit_rate']) : null;
            $total = $rate === null ? WorkTimeService::units($data['agreed_total']) : $rate * (int) $data['quantity'];
            abort_if($total > 100000000, 422, 'Сума однієї роботи не може перевищувати 1 000 000 грн.');
            $values = ['employee_id' => (int) $data['employee_id'], 'work_date' => $data['date'], 'description' => $data['description'],
                'quantity' => (int) $data['quantity'], 'unit' => $data['unit'], 'pricing_mode' => $data['pricing_mode'],
                'unit_rate_cents' => $rate, 'total_cents' => $total, 'paid_cents' => WorkTimeService::units($data['paid']), 'note' => $data['note'] ?? null];
            $same = fn ($row) => $row && collect($values)->every(fn ($value, $key) => $value === null ? $row->$key === null : (string) $value === (string) $row->$key);
            if (! $id) {
                // Повтор після втрати відповіді не створює друге нарахування.
                DB::table('work_piecework_entries')->insertOrIgnore($values + ['request_key' => $data['request_key'],
                    'version' => 1, 'updated_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
                $row = DB::table('work_piecework_entries')->where('request_key', $data['request_key'])->lockForUpdate()->first();
                abort_unless($same($row), 409, 'Цей запит уже збережений з іншими даними. Оновіть список робіт.');
                $id = (int) $row->id;
            } elseif (! $same($before)) {
                abort_unless((int) $before->version === (int) $data['version'], 409, 'Роботу вже змінили в іншому вікні. Оновіть список.');
                DB::table('work_piecework_entries')->where('id', $id)->update($values + ['version' => $before->version + 1, 'updated_by' => $actor, 'updated_at' => now()]);
            }
            $after = DB::table('work_piecework_entries')->where('id', $id)->first();
            if (($before && ! $same($before)) || (! $before && ! DB::table('work_time_revisions')->where('subject_type', 'piecework')->where('subject_id', $id)->exists())) {
                DB::table('work_time_revisions')->insert(['subject_type' => 'piecework', 'subject_id' => $id, 'actor_id' => $actor,
                    'before' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
                    'after' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now()]);
            }

            return $this->entry($after);
        }, 3);
    }
}
