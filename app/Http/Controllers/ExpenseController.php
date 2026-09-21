<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseAccount;
use App\Models\ExpenseCategory;
use App\Models\ExpenseGroup;
use App\Models\ExpensePayment;
use App\Models\User;
use App\Services\Expenses\ExpenseData;
use App\Services\Expenses\ExpenseQuery;
use App\Services\Expenses\Money;
use App\Services\Expenses\ReceiptFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    public function meta()
    {
        return response()->json(['categories' => ExpenseCategory::orderBy('id')->get(['id', 'name', 'color']), 'accounts' => ExpenseAccount::orderBy('id')->get(['id', 'name']), 'groups' => ExpenseGroup::orderByDesc('id')->get(['id', 'name', 'note']), 'currencies' => ['UAH', 'USD', 'EUR', 'PLN', 'CNY'], 'today' => now('Europe/Kyiv')->toDateString()])->header('Cache-Control', 'no-store, private');
    }

    public function category(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120', 'unique:expense_categories,name'], 'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/']], $this->messages());
        $data['color'] ??= '#6954df';

        return response()->json(['data' => ExpenseCategory::create($data)->only(['id', 'name', 'color'])], 201);
    }

    public function account(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120', 'unique:expense_accounts,name']], $this->messages());

        return response()->json(['data' => ExpenseAccount::create($data)->only(['id', 'name'])], 201);
    }

    public function group(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:180'], 'note' => ['nullable', 'string', 'max:10000']], $this->messages());

        return response()->json(['data' => ExpenseGroup::create($data)->only(['id', 'name', 'note'])], 201);
    }

    public function index(Request $request)
    {
        return response()->json((new ExpenseQuery($request))->result())->header('Cache-Control', 'no-store, private');
    }

    public function show(Expense $expense)
    {
        return response()->json(['data' => ExpenseData::detail($expense)])->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request)
    {
        $rules = $this->expenseRules() + ['idempotency_key' => ['nullable', 'uuid'], 'payment' => ['nullable', 'array']];
        foreach ($this->paymentRules(false) as $key => $rule) {
            $rules['payment.'.$key] = array_map(fn ($r) => $r === 'required' ? 'required_with:payment' : $r, $rule);
        }
        $data = $request->validate($rules, $this->messages());
        $expense = DB::transaction(function () use ($data, $request) {
            // Серіалізація створення захищає ідемпотентний запит до появи самого запису.
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $hash = $this->hash($data);
            if (! empty($data['idempotency_key'])) {
                $existing = Expense::where('created_by', $request->user()->id)->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    abort_if($existing->request_hash !== $hash, 409, 'Цей ключ уже використаний для іншої оплати.');

                    return $existing;
                }
            }
            $paymentData = $data['payment'] ?? null;
            $attributes = $this->attributes($data);
            $expense = Expense::create($attributes + ['created_by' => $request->user()->id, 'version' => 1, 'idempotency_key' => $data['idempotency_key'] ?? null, 'request_hash' => $hash]);
            if ($paymentData) {
                $this->savePayment($expense, $paymentData, $request->user()->id);
            }

            return $expense;
        }, 3);

        return response()->json(['data' => ExpenseData::detail($expense)], 201);
    }

    public function update(Request $request, Expense $expense)
    {
        $data = $request->validate($this->expenseRules() + ['version' => ['required', 'integer', 'min:1']], $this->messages());
        $expense = DB::transaction(function () use ($expense, $data) {
            $locked = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
            $this->checkVersion($locked, $data);
            $paid = (int) $locked->payments()->sum('amount_minor');
            if ($paid > 0 && $data['currency'] !== $locked->currency) {
                throw ValidationException::withMessages(['currency' => 'Не можна змінити валюту після внесення оплат.']);
            }
            if (Money::minor($data['amount']) < $paid) {
                throw ValidationException::withMessages(['amount' => 'Загальна сума не може бути меншою за вже сплачену.']);
            }
            $locked->update($this->attributes($data) + ['version' => $locked->version + 1]);

            return $locked;
        }, 3);

        return response()->json(['data' => ExpenseData::detail($expense)]);
    }

    public function destroy(Request $request, Expense $expense)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']], $this->messages());
        DB::transaction(function () use ($expense, $data) {
            $locked = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
            $this->checkVersion($locked, $data);
            $paths = DB::table('expense_receipts')->join('expense_payments', 'expense_payments.id', '=', 'expense_receipts.payment_id')->where('expense_payments.expense_id', $locked->id)->pluck('expense_receipts.path')->all();
            $locked->delete();
            ReceiptFiles::deleteAfterCommit($paths);
        }, 3);

        return response()->json(['ok' => true]);
    }

    public function storePayment(Request $request, Expense $expense)
    {
        $data = $request->validate($this->paymentRules() + ['idempotency_key' => ['nullable', 'uuid']], $this->messages());
        $expense = DB::transaction(function () use ($expense, $data, $request) {
            $locked = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
            $hash = $this->hash($data);
            if (! empty($data['idempotency_key'])) {
                $existing = $locked->payments()->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    abort_if($existing->request_hash !== $hash, 409, 'Цей ключ уже використаний для іншої оплати.');

                    return $locked;
                }
            }
            $this->checkVersion($locked, $data);
            $this->savePayment($locked, $data, $request->user()->id, null, $hash);
            $locked->increment('version');

            return $locked;
        }, 3);

        return response()->json(['data' => ExpenseData::detail($expense)], 201);
    }

    public function updatePayment(Request $request, Expense $expense, ExpensePayment $payment)
    {
        abort_unless($payment->expense_id === $expense->id, 404);
        $data = $request->validate($this->paymentRules(), $this->messages());
        $expense = DB::transaction(function () use ($expense, $payment, $data, $request) {
            $locked = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
            $this->checkVersion($locked, $data);
            $current = $locked->payments()->whereKey($payment->id)->firstOrFail();
            $this->savePayment($locked, $data, $request->user()->id, $current);
            $locked->increment('version');

            return $locked;
        }, 3);

        return response()->json(['data' => ExpenseData::detail($expense)]);
    }

    public function destroyPayment(Request $request, Expense $expense, ExpensePayment $payment)
    {
        abort_unless($payment->expense_id === $expense->id, 404);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']], $this->messages());
        $expense = DB::transaction(function () use ($expense, $payment, $data) {
            $locked = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
            $this->checkVersion($locked, $data);
            $current = $locked->payments()->whereKey($payment->id)->firstOrFail();
            $paths = $current->receipts()->pluck('path')->all();
            $current->delete();
            $locked->increment('version');
            ReceiptFiles::deleteAfterCommit($paths);

            return $locked;
        }, 3);

        return response()->json(['data' => ExpenseData::detail($expense)]);
    }

    public function export(Request $request)
    {
        $query = (new ExpenseQuery($request))->payments(true)->with(['expense.category', 'expense.group', 'account']);

        return response()->streamDownload(function () use ($query) {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['Дата', 'За що', 'Отримувач', 'Категорія', 'Група', 'Рахунок', 'Сума', 'Валюта', 'Курс', 'Сума, грн', 'Коментар'], ';', '"', '');
            foreach ($query->lazyById(250, 'expense_payments.id', 'id') as $payment) {
                $e = $payment->expense;
                $row = [$payment->paid_on, $e->title, $e->recipient, $e->category->name, $e->group?->name, $payment->account->name, Money::display($payment->amount_minor), $e->currency, $payment->exchange_rate, Money::display($payment->amount_uah_minor), $payment->note];
                $row = array_map(function ($value) {
                    $text = (string) ($value ?? '');

                    return preg_match('/^[\s\x00-\x20]*[=+@-]/u', $text) ? "'".$text : $text;
                }, $row);
                fputcsv($stream, $row, ';', '"', '');
            }
            fclose($stream);
        }, 'expenses-'.now('Europe/Kyiv')->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    private function expenseRules(): array
    {
        return ['title' => ['required', 'string', 'max:255'], 'recipient' => ['nullable', 'string', 'max:255'],
            'category_id' => ['required', 'integer', 'exists:expense_categories,id'], 'group_id' => ['nullable', 'integer', 'exists:expense_groups,id'],
            'account_id' => ['nullable', 'integer', 'exists:expense_accounts,id'], 'amount' => $this->amountRules(),
            'currency' => ['required', Rule::in(['UAH', 'USD', 'EUR', 'PLN', 'CNY'])], 'expected_exchange_rate' => $this->rateRules(),
            'due_on' => ['required', 'date_format:Y-m-d'], 'note' => ['nullable', 'string', 'max:10000']];
    }

    private function paymentRules(bool $version = true): array
    {
        return ['amount' => $this->amountRules(), 'account_id' => ['required', 'integer', 'exists:expense_accounts,id'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Europe/Kyiv')->toDateString()],
            'exchange_rate' => $this->rateRules(), 'note' => ['nullable', 'string', 'max:10000']]
            + ($version ? ['version' => ['required', 'integer', 'min:1']] : []);
    }

    private function amountRules(): array
    {
        return ['required', 'numeric', 'min:0.01', 'max:9999999.99', 'regex:/^\d{1,7}(\.\d{1,2})?$/'];
    }

    private function rateRules(): array
    {
        return ['required', 'numeric', 'min:0.000001', 'max:1000', 'regex:/^\d{1,4}(\.\d{1,6})?$/'];
    }

    private function attributes(array $data): array
    {
        $attributes = array_intersect_key($data, array_flip(['title', 'recipient', 'category_id', 'group_id', 'account_id', 'currency', 'expected_exchange_rate', 'due_on', 'note']));
        $attributes['amount_minor'] = Money::minor($data['amount']);
        if ($data['currency'] === 'UAH') {
            $attributes['expected_exchange_rate'] = '1.000000';
        }

        return $attributes;
    }

    private function savePayment(Expense $expense, array $data, int $userId, ?ExpensePayment $payment = null, ?string $hash = null): void
    {
        $amount = Money::minor($data['amount']);
        $otherPaid = (int) $expense->payments()->when($payment, fn ($q) => $q->where('id', '!=', $payment->id))->sum('amount_minor');
        if ($otherPaid + $amount > $expense->amount_minor) {
            throw ValidationException::withMessages(['amount' => 'Оплата перевищує залишок зобов’язання.']);
        }
        $rate = $expense->currency === 'UAH' ? '1.000000' : (string) $data['exchange_rate'];
        $attributes = ['amount_minor' => $amount, 'exchange_rate' => $rate, 'amount_uah_minor' => Money::convert($amount, $rate), 'account_id' => $data['account_id'], 'paid_on' => $data['paid_on'], 'note' => $data['note'] ?? null];
        if ($payment) {
            $payment->update($attributes);
        } else {
            $expense->payments()->create($attributes + ['created_by' => $userId, 'idempotency_key' => $data['idempotency_key'] ?? null, 'request_hash' => $hash]);
        }
    }

    private function checkVersion(Expense $expense, array $data): void
    {
        abort_if($expense->version !== (int) $data['version'], 409, 'Запис уже змінено. Оновіть його та повторіть дію.');
    }

    private function hash(array $data): string
    {
        unset($data['version'], $data['idempotency_key']);
        ksort($data);

        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function messages(): array
    {
        return ['required' => 'Заповніть це поле.', 'required_with' => 'Заповніть дані оплати.', 'numeric' => 'Вкажіть коректну суму.', 'integer' => 'Вкажіть ціле число.', 'exists' => 'Вибраний запис більше не існує.', 'unique' => 'Така назва вже існує.', 'max' => 'Значення перевищує дозволений розмір (:max).', 'min' => 'Значення має бути не менше :min.', 'regex' => 'Неправильний формат значення.', 'date_format' => 'Вкажіть дату у форматі РРРР-ММ-ДД.', 'before_or_equal' => 'Дата оплати не може бути в майбутньому.', 'uuid' => 'Некоректний ключ повторного запиту.', 'in' => 'Виберіть підтримуване значення.', 'string' => 'Вкажіть текстове значення.', 'array' => 'Неправильний формат даних.'];
    }
}
