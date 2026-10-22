<?php

namespace Tests\Feature\Expenses;

use App\Models\ExpenseAccount;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpenseBalanceTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
    }

    private function balanceAccount(): int
    {
        return ExpenseAccount::where('system_key', 'balance')->value('id');
    }

    private function topUp(string $amount = '3800.00', ?string $key = null): array
    {
        return ['amount' => $amount, 'account_id' => ExpenseAccount::where('name', 'Каса')->value('id'), 'note' => 'Отримані кошти', 'idempotency_key' => $key ?? (string) Str::uuid()];
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['title' => 'Доставка матеріалів', 'amount' => '5000.00', 'currency' => 'UAH',
            'account_id' => $this->balanceAccount(), 'expected_exchange_rate' => '1', 'due_on' => now('Europe/Kyiv')->toDateString()], $overrides);
    }

    private function payment(array $overrides = []): array
    {
        return array_replace(['amount' => '2000.00', 'account_id' => $this->balanceAccount(), 'exchange_rate' => '1',
            'paid_on' => now('Europe/Kyiv')->toDateString()], $overrides);
    }

    private function assertBalance(string $amount, string $spent = '0.00'): void
    {
        $this->getJson('/api/expenses/balance')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('balance.amount', $amount)->assertJsonPath('balance.spent', $spent);
    }

    public function test_balance_access_is_owner_only(): void
    {
        $this->getJson('/api/expenses/balance')->assertUnauthorized();
        foreach (['operator', 'packer'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/expenses/balance')->assertForbidden();
            $this->postJson('/api/expenses/balance', $this->topUp())->assertForbidden();
        }
    }

    public function test_topups_are_exact_idempotent_and_validated(): void
    {
        $this->owner();
        $data = $this->topUp('3800.05');
        $this->postJson('/api/expenses/balance', $data)->assertCreated()->assertJsonPath('balance.amount', '3800.05');
        $this->postJson('/api/expenses/balance', $data)->assertCreated()->assertJsonPath('balance.amount', '3800.05');
        $this->postJson('/api/expenses/balance', array_replace($data, ['amount' => '4000']))->assertConflict();
        foreach (['-1', '0', '1e3', '0.001', '10000000.00'] as $invalid) {
            $this->postJson('/api/expenses/balance', $this->topUp($invalid))->assertUnprocessable();
        }
        $this->postJson('/api/expenses/balance', array_replace($this->topUp(), ['account_id' => $this->balanceAccount()]))->assertUnprocessable();
        $this->assertDatabaseCount('expense_balance_entries', 1);
        $this->assertBalance('3800.05');
    }

    public function test_old_payments_and_plans_do_not_reduce_balance_and_legacy_fields_are_preserved(): void
    {
        $this->owner();
        $cash = ExpenseAccount::where('name', 'Каса')->value('id');
        $old = $this->postJson('/api/expenses', $this->payload(['category_id' => ExpenseCategory::first()->id, 'recipient' => 'Постачальник',
            'payment' => $this->payment(['account_id' => $cash])]))->assertCreated()->json('data');
        $before = DB::table('expense_payments')->get()->toJson();
        $this->postJson('/api/expenses/balance', $this->topUp())->assertCreated();
        $this->postJson('/api/expenses', $this->payload())->assertCreated()->assertJsonPath('data.category_id', null);
        $this->putJson('/api/expenses/'.$old['id'], $this->payload(['version' => 1]))->assertOk()
            ->assertJsonPath('data.recipient', 'Постачальник')->assertJsonPath('data.category_id', $old['category_id']);
        $this->assertSame($before, DB::table('expense_payments')->get()->toJson());
        $this->assertBalance('3800.00');
        $this->getJson('/api/expenses?view=planned')->assertOk();
        $this->get('/api/expenses/export')->assertOk();
    }

    public function test_payment_debits_once_and_new_expense_can_have_no_category(): void
    {
        $this->owner();
        $this->postJson('/api/expenses/balance', $this->topUp())->assertCreated();
        $payload = $this->payload(['payment' => $this->payment(), 'idempotency_key' => (string) Str::uuid()]);
        $e = $this->postJson('/api/expenses', $payload)->assertCreated()->assertJsonPath('data.category_id', null)->json('data');
        $this->postJson('/api/expenses', $payload)->assertCreated()->assertJsonPath('data.id', $e['id']);
        $this->assertBalance('1800.00', '2000.00');
        $this->assertDatabaseCount('expense_balance_entries', 2);
        $this->getJson('/api/expenses')->assertOk()->assertJsonPath('data.0.category', null);
        $this->get('/api/expenses/export')->assertOk();
    }

    public function test_insufficient_funds_roll_back_the_payment_and_new_expense(): void
    {
        $this->owner();
        $this->postJson('/api/expenses/balance', $this->topUp('1800'))->assertCreated();
        $response = $this->postJson('/api/expenses', $this->payload(['payment' => $this->payment()]))->assertUnprocessable()->assertJsonValidationErrors('balance');
        $message = $response->json('errors.balance.0');
        $this->assertStringContainsString('1800.00', $message);
        $this->assertStringContainsString('2000.00', $message);
        $this->assertStringContainsString('200.00', $message);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('expense_payments', 0);
        $this->assertBalance('1800.00');
    }

    public function test_payment_changes_refund_or_debit_only_the_difference(): void
    {
        $this->owner();
        $this->postJson('/api/expenses/balance', $this->topUp())->assertCreated();
        $e = $this->postJson('/api/expenses', $this->payload(['payment' => $this->payment()]))->assertCreated()->json('data');
        $url = '/api/expenses/'.$e['id'].'/payments/'.$e['payments'][0]['id'];
        $this->putJson($url, $this->payment(['version' => 1, 'amount' => '1500']))->assertOk();
        $this->assertBalance('2300.00', '1500.00');
        $count = DB::table('expense_balance_entries')->count();
        $this->putJson($url, $this->payment(['version' => 2, 'amount' => '1500']))->assertOk();
        $this->assertDatabaseCount('expense_balance_entries', $count);
        $cash = ExpenseAccount::where('name', 'Каса')->value('id');
        $this->putJson($url, $this->payment(['version' => 3, 'account_id' => $cash]))->assertOk();
        $this->assertBalance('3800.00');
        $this->putJson($url, $this->payment(['version' => 4]))->assertOk();
        $this->assertBalance('1800.00', '2000.00');
        $this->deleteJson($url, ['version' => 5])->assertOk();
        $this->assertBalance('3800.00');
        $this->assertDatabaseCount('expense_payments', 0);
    }

    public function test_failed_payment_increase_keeps_previous_payment_and_ledger(): void
    {
        $this->owner();
        $this->postJson('/api/expenses/balance', $this->topUp())->assertCreated();
        $e = $this->postJson('/api/expenses', $this->payload(['payment' => $this->payment()]))->assertCreated()->json('data');
        $this->putJson('/api/expenses/'.$e['id'].'/payments/'.$e['payments'][0]['id'], $this->payment(['version' => 1, 'amount' => '4000']))->assertUnprocessable()->assertJsonValidationErrors('balance');
        $this->assertBalance('1800.00', '2000.00');
        $this->assertDatabaseHas('expense_payments', ['id' => $e['payments'][0]['id'], 'amount_minor' => 200000]);
        $this->assertDatabaseHas('expenses', ['id' => $e['id'], 'version' => 1]);
        $this->assertDatabaseCount('expense_balance_entries', 2);
    }

    public function test_old_plan_can_be_paid_from_balance_and_parent_delete_refunds_all_payments(): void
    {
        $this->owner();
        $e = $this->postJson('/api/expenses', $this->payload())->assertCreated()->json('data');
        $this->postJson('/api/expenses/balance', $this->topUp())->assertCreated();
        $url = '/api/expenses/'.$e['id'];
        $data = $this->payment(['version' => 1, 'idempotency_key' => (string) Str::uuid()]);
        $this->postJson($url.'/payments', $data)->assertCreated();
        $this->postJson($url.'/payments', $data)->assertCreated();
        $this->postJson($url.'/payments', $this->payment(['amount' => '1800', 'version' => 2]))->assertCreated();
        $this->assertBalance('0.00', '3800.00');
        $this->deleteJson($url, ['version' => 3])->assertOk();
        $this->assertBalance('3800.00');
        $this->assertDatabaseCount('expense_payments', 0);
        $this->assertDatabaseCount('expense_balance_entries', 5);
        $this->getJson('/api/expenses/balance')->assertJsonPath('data.0.expense_id', null);
    }

    public function test_foreign_currency_spends_exact_uah_equivalent(): void
    {
        $this->owner();
        $this->postJson('/api/expenses/balance', $this->topUp())->assertCreated();
        $this->postJson('/api/expenses', $this->payload(['currency' => 'USD', 'amount' => '100', 'expected_exchange_rate' => '40',
            'payment' => $this->payment(['amount' => '40', 'exchange_rate' => '41.123456'])]))->assertCreated()->assertJsonPath('data.payments.0.amount_uah', '1644.94');
        $this->assertBalance('2155.06', '1644.94');
    }
}
