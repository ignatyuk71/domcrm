<?php

namespace App\Services\Expenses;

use App\Models\Expense;
use App\Models\ExpensePayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class ExpenseQuery
{
    public array $filters;

    public function __construct(Request $request)
    {
        $this->filters = $request->validate([
            'view' => ['sometimes', Rule::in(['payments', 'planned', 'groups'])],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:200'], 'category_id' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'account_id' => ['nullable', 'integer', 'exists:expense_accounts,id'], 'group_id' => ['nullable', 'integer', 'exists:expense_groups,id'],
            'missing_receipts' => ['nullable', 'boolean'], 'page' => ['nullable', 'integer', 'min:1'],
        ], ['date_format' => 'Вкажіть дату у форматі РРРР-ММ-ДД.', 'exists' => 'Вибраний запис більше не існує.', 'in' => 'Непідтримуваний режим перегляду.', 'integer' => 'Вкажіть ціле число.', 'min' => 'Значення має бути не менше :min.', 'max' => 'Значення перевищує дозволений розмір (:max).', 'boolean' => 'Неправильне значення фільтра.', 'string' => 'Вкажіть текстове значення.']);
        $today = now('Europe/Kyiv');
        $this->filters['from'] ??= $today->copy()->startOfMonth()->toDateString();
        $this->filters['to'] ??= $today->copy()->endOfMonth()->toDateString();
        abort_if($this->filters['to'] < $this->filters['from'], 422, 'Кінцева дата повинна бути не раніше початкової.');
    }

    public function payments(bool $list = false): Builder
    {
        $query = ExpensePayment::query()->whereBetween('paid_on', [$this->filters['from'], $this->filters['to']])
            ->whereHas('expense', fn (Builder $expenses) => $this->filterExpenses($expenses));
        if ($id = $this->filters['account_id'] ?? null) {
            $query->where('expense_payments.account_id', $id);
        }
        if ($list && ($this->filters['missing_receipts'] ?? false)) {
            $query->doesntHave('receipts');
        }

        return $query;
    }

    public function planned(): Builder
    {
        $totals = DB::table('expense_payments')->selectRaw('expense_id, SUM(amount_minor) as total_paid')->groupBy('expense_id');
        $query = Expense::query()->leftJoinSub($totals, 'payment_totals', 'expenses.id', '=', 'payment_totals.expense_id')
            ->whereBetween('due_on', [$this->filters['from'], $this->filters['to']])
            ->whereRaw('expenses.amount_minor > COALESCE(payment_totals.total_paid, 0)');
        $this->filterExpenses($query);
        if ($id = $this->filters['account_id'] ?? null) {
            $query->where('expenses.account_id', $id);
        }

        return $query;
    }

    private function filterExpenses(Builder $query): void
    {
        foreach (['category_id', 'group_id'] as $key) {
            if ($id = $this->filters[$key] ?? null) {
                $query->where('expenses.'.$key, $id);
            }
        }
        if ($q = $this->filters['q'] ?? null) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
            $query->where(fn (Builder $search) => $search->whereRaw("expenses.title LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("expenses.recipient LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("expenses.note LIKE ? ESCAPE '!'", [$pattern])->orWhereHas('group', fn ($group) => $group->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])));
        }
    }

    public function result(): array
    {
        $paid = $this->payments()->selectRaw('COALESCE(SUM(amount_uah_minor),0) as amount, COUNT(*) as count')->first();
        // Кожне зобовʼязання округлюється окремо, як у його картці.
        $pendingSql = 'ROUND((expenses.amount_minor - COALESCE(payment_totals.total_paid,0)) * expenses.expected_exchange_rate, 0)';
        $pending = $this->planned()->selectRaw("COALESCE(SUM($pendingSql),0) as amount, COUNT(*) as count")->first();
        $breakdown = [];
        foreach (['categories', 'recipients', 'groups', 'accounts'] as $dimension) {
            $breakdown[$dimension] = $this->breakdown($dimension);
        }
        $view = $this->filters['view'] ?? 'payments';
        if ($view === 'planned') {
            $page = $this->planned()->select('expenses.*')->selectRaw('COALESCE(payment_totals.total_paid,0) as paid_minor')
                ->with(['category', 'group', 'account'])->orderBy('due_on')->orderBy('expenses.id')->paginate(25);
            $rows = $page->getCollection()->map(fn ($expense) => ExpenseData::planned($expense))->all();
        } elseif ($view === 'groups') {
            $paymentGroups = $this->payments()->join('expenses', 'expenses.id', '=', 'expense_payments.expense_id')
                ->whereNotNull('expenses.group_id')->selectRaw('expenses.group_id, SUM(amount_uah_minor) as paid, COUNT(*) as payment_count')->groupBy('expenses.group_id');
            $pendingGroups = $this->planned()->whereNotNull('expenses.group_id')->selectRaw("expenses.group_id, SUM($pendingSql) as pending")->groupBy('expenses.group_id');
            $page = DB::table('expense_groups as g')->leftJoinSub($paymentGroups->toBase(), 'pg', 'g.id', '=', 'pg.group_id')
                ->leftJoinSub($pendingGroups->toBase(), 'due', 'g.id', '=', 'due.group_id')
                ->where(fn ($q) => $q->whereNotNull('pg.group_id')->orWhereNotNull('due.group_id'))
                ->select('g.*')->selectRaw('COALESCE(pg.paid,0) as paid, COALESCE(pg.payment_count,0) as payment_count, COALESCE(due.pending,0) as pending')
                ->orderByDesc('paid')->orderBy('g.id')->paginate(25);
            $rows = $page->getCollection()->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'note' => $g->note, 'paid_amount' => Money::display($g->paid), 'payment_count' => (int) $g->payment_count, 'pending_amount' => Money::display($g->pending)])->all();
        } else {
            $page = $this->payments(true)->with(['expense.category', 'expense.group', 'account', 'receipts'])->orderByDesc('paid_on')->orderByDesc('id')->paginate(25);
            $rows = $page->getCollection()->map(fn ($payment) => ExpenseData::payment($payment))->all();
        }

        return [
            'summary' => ['paid_amount' => Money::display($paid->amount), 'paid_count' => (int) $paid->count,
                'pending_amount' => Money::display($pending->amount), 'pending_count' => (int) $pending->count,
                'missing_receipts_count' => $this->payments()->doesntHave('receipts')->count(),
                'demo_count' => $this->payments()->whereHas('expense', fn ($q) => $q->where('is_demo', true))->count() + $this->planned()->where('is_demo', true)->count()],
            'breakdown' => $breakdown, 'data' => array_values($rows),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
        ];
    }

    private function breakdown(string $dimension): array
    {
        $query = $this->payments()->join('expenses as e', 'e.id', '=', 'expense_payments.expense_id');
        if ($dimension === 'recipients') {
            return $query->selectRaw("e.recipient as recipient_key, COALESCE(e.recipient, 'Не вказано') as name, SUM(amount_uah_minor) as amount")
                ->groupBy('e.recipient')->orderByDesc('amount')->get()->map(fn ($row) => ['id' => $row->recipient_key, 'name' => $row->name, 'amount' => Money::display($row->amount)])->all();
        }
        $table = ['categories' => 'expense_categories', 'groups' => 'expense_groups', 'accounts' => 'expense_accounts'][$dimension];
        $column = ['categories' => 'e.category_id', 'groups' => 'e.group_id', 'accounts' => 'expense_payments.account_id'][$dimension];
        if ($dimension === 'groups') {
            return $query->leftJoin('expense_groups as d', 'd.id', '=', 'e.group_id')->select('d.id')->selectRaw("COALESCE(d.name, 'Без групи') as name, SUM(amount_uah_minor) as amount")
                ->groupBy('d.id', 'd.name')->orderByDesc('amount')->get()->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'amount' => Money::display($row->amount)])->all();
        }
        $query->join($table.' as d', 'd.id', '=', $column)->select('d.id', 'd.name')->selectRaw('SUM(amount_uah_minor) as amount')->groupBy('d.id', 'd.name');
        if ($dimension === 'categories') {
            $query->addSelect('d.color')->groupBy('d.color');
        }

        return $query->orderByDesc('amount')->get()->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'amount' => Money::display($row->amount)] + ($dimension === 'categories' ? ['color' => $row->color] : []))->all();
    }
}
