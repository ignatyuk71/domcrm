<?php

namespace App\Services\Expenses;

use App\Models\Expense;
use App\Models\ExpensePayment;

final class ExpenseData
{
    public static function payment(ExpensePayment $payment): array
    {
        $expense = $payment->expense;

        return [
            'id' => $payment->id, 'expense_id' => $expense->id, 'title' => $expense->title, 'recipient' => $expense->recipient,
            'category' => $expense->category->only(['id', 'name', 'color']), 'group' => $expense->group?->only(['id', 'name']),
            'account' => $payment->account->only(['id', 'name']), 'amount' => Money::display($payment->amount_minor),
            'currency' => $expense->currency, 'exchange_rate' => $payment->exchange_rate, 'amount_uah' => Money::display($payment->amount_uah_minor),
            'paid_on' => $payment->paid_on, 'note' => $payment->note, 'is_demo' => $expense->is_demo, 'expense_version' => $expense->version,
            'receipts' => $payment->receipts->map(fn ($receipt) => [
                'id' => $receipt->id, 'name' => $receipt->original_name, 'mime_type' => $receipt->mime_type, 'size' => (int) $receipt->size,
                'url' => '/api/expenses/receipts/'.$receipt->id, 'download_url' => '/api/expenses/receipts/'.$receipt->id.'?download=1',
            ])->values()->all(),
        ];
    }

    public static function planned(Expense $expense): array
    {
        $paid = (int) $expense->paid_minor;
        $remaining = max(0, $expense->amount_minor - $paid);

        return [
            'id' => $expense->id, 'title' => $expense->title, 'recipient' => $expense->recipient,
            'category' => $expense->category->only(['id', 'name', 'color']), 'group' => $expense->group?->only(['id', 'name']),
            'account' => $expense->account?->only(['id', 'name']), 'amount' => Money::display($expense->amount_minor),
            'currency' => $expense->currency, 'expected_exchange_rate' => $expense->expected_exchange_rate,
            'paid_amount' => Money::display($paid), 'remaining_amount' => Money::display($remaining),
            'remaining_amount_uah' => Money::display(Money::convert($remaining, $expense->expected_exchange_rate)),
            'due_on' => $expense->due_on, 'note' => $expense->note, 'status' => $paid > 0 ? 'partial' : 'pending', 'is_demo' => $expense->is_demo, 'version' => $expense->version,
        ];
    }

    public static function detail(Expense $expense): array
    {
        $expense->load(['category', 'group', 'account', 'payments.account', 'payments.receipts']);
        $paid = (int) $expense->payments->sum('amount_minor');
        foreach ($expense->payments as $payment) {
            $payment->setRelation('expense', $expense);
        }

        return $expense->only(['id', 'title', 'recipient', 'category_id', 'group_id', 'account_id', 'currency', 'expected_exchange_rate', 'due_on', 'note', 'is_demo', 'version']) + [
            'amount' => Money::display($expense->amount_minor), 'paid_amount' => Money::display($paid),
            'remaining_amount' => Money::display($expense->amount_minor - $paid),
            'payments' => $expense->payments->map(fn ($payment) => self::payment($payment))->values()->all(),
        ];
    }
}
