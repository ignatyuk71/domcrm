<?php

namespace App\Services\Expenses;

use App\Models\ExpensePayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ExpenseBalanceService
{
    public function report(int $page = 1): array
    {
        // Залишок та історія читаються з одного узгодженого знімка.
        return DB::transaction(fn () => $this->readReport($page));
    }

    private function readReport(int $page): array
    {
        $balance = DB::table('expense_balances')->where('id', 1)->first();
        $entries = DB::table('expense_balance_entries as e')->leftJoin('expense_accounts as a', 'a.id', '=', 'e.source_account_id')
            ->where('e.balance_id', 1)->select('e.*', 'a.name as source_name')->orderByDesc('e.id')->paginate(15, ['*'], 'page', $page);

        return [
            'balance' => ['amount' => Money::display($balance->amount_minor), 'incoming' => Money::display($balance->incoming_minor),
                'spent' => Money::display($balance->incoming_minor - $balance->amount_minor), 'currency' => 'UAH', 'account_id' => $balance->account_id],
            'data' => $entries->getCollection()->map(fn ($entry) => [
                'id' => $entry->id, 'kind' => $entry->kind, 'amount' => Money::display($entry->amount_minor),
                'balance_after' => Money::display($entry->balance_after_minor), 'title' => $entry->title, 'note' => $entry->note,
                'source_name' => $entry->source_name, 'occurred_on' => $entry->occurred_on, 'expense_id' => $entry->expense_id,
            ])->all(),
            'meta' => ['current_page' => $entries->currentPage(), 'last_page' => $entries->lastPage(), 'total' => $entries->total()],
        ];
    }

    public function topUp(array $data, int $userId): void
    {
        DB::transaction(function () use ($data, $userId) {
            $balance = DB::table('expense_balances')->where('id', 1)->lockForUpdate()->first();
            $amount = Money::minor($data['amount']);
            $hash = hash('sha256', json_encode([$amount, (int) $data['account_id'], $data['note'] ?? null], JSON_UNESCAPED_UNICODE));
            $existing = DB::table('expense_balance_entries')->where('created_by', $userId)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_if($existing->request_hash !== $hash, 409, 'Це поповнення вже збережене з іншими даними. Перевірте історію перед новим поповненням.');

                return;
            }
            $this->record($balance, $amount, ['kind' => 'topup', 'title' => 'Надходження', 'source_account_id' => $data['account_id'],
                'note' => $data['note'] ?? null, 'created_by' => $userId, 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash]);
        }, 3);
    }

    // Викликається в транзакції оплати після блокування її витрати.
    public function syncPayment(ExpensePayment $payment, int $userId, bool $deleted = false): void
    {
        $balance = DB::table('expense_balances')->where('id', 1)->first();
        $newDebit = ! $deleted && (int) $payment->account_id === (int) $balance->account_id ? $payment->amount_uah_minor : 0;
        $oldDebit = -(int) DB::table('expense_balance_entries')->where('payment_id', $payment->id)->sum('amount_minor');
        $delta = $oldDebit - $newDebit;
        if ($delta === 0) {
            return;
        }
        // Спільний рядок захищає від двох одночасних списань понад залишок.
        $balance = DB::table('expense_balances')->where('id', 1)->lockForUpdate()->first();
        if ($balance->amount_minor + $delta < 0) {
            $available = Money::display($balance->amount_minor + $oldDebit);
            $required = Money::display($newDebit);
            $missing = Money::display(-($balance->amount_minor + $delta));
            throw ValidationException::withMessages(['balance' => "На балансі: {$available} грн. Сума оплати: {$required} грн. Не вистачає: {$missing} грн."]);
        }
        $this->record($balance, $delta, ['kind' => $delta < 0 ? 'payment' : 'refund', 'payment_id' => $payment->id,
            'expense_id' => $payment->expense_id, 'title' => $payment->expense->title,
            'note' => $deleted ? 'Повернення коштів після видалення оплати' : ($oldDebit > 0 ? 'Коригування оплати з балансу' : $payment->note),
            'created_by' => $userId]);
    }

    private function record(object $balance, int $delta, array $attributes): void
    {
        $now = now();
        $after = (int) $balance->amount_minor + $delta;
        DB::table('expense_balances')->where('id', 1)->update(['amount_minor' => $after,
            'incoming_minor' => (int) $balance->incoming_minor + ($attributes['kind'] === 'topup' ? $delta : 0), 'updated_at' => $now]);
        DB::table('expense_balance_entries')->insert($attributes + ['balance_id' => 1, 'amount_minor' => $delta,
            'balance_after_minor' => $after, 'occurred_on' => now('Europe/Kyiv')->toDateString(), 'created_at' => $now, 'updated_at' => $now]);
    }
}
