<?php

namespace App\Http\Controllers;

use App\Services\Expenses\ExpenseBalanceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseBalanceController extends Controller
{
    public function index(Request $request, ExpenseBalanceService $balance)
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return response()->json($balance->report((int) ($data['page'] ?? 1)))->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, ExpenseBalanceService $balance)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999.99', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
            'account_id' => ['required', 'integer', Rule::exists('expense_accounts', 'id')->whereNull('system_key')],
            'note' => ['nullable', 'string', 'max:5000'], 'idempotency_key' => ['required', 'uuid'],
        ], ['required' => 'Заповніть це поле.', 'numeric' => 'Вкажіть коректну суму.', 'min' => 'Сума має бути більшою за нуль.',
            'max' => 'Значення перевищує дозволений розмір.', 'regex' => 'Вкажіть суму до двох знаків після коми.',
            'exists' => 'Оберіть джерело отриманих коштів.', 'uuid' => 'Оновіть сторінку перед поповненням.']);
        $balance->topUp($data, $request->user()->id);

        return response()->json($balance->report(), 201)->header('Cache-Control', 'no-store, private');
    }
}
