<?php

namespace Tests\Feature\Fiscal;

use App\Models\CheckboxSetting;
use App\Models\FiscalReceipt;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancePaymentBreakdownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTime(15, 0));
        CheckboxSetting::create(['enabled' => false]);
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create(['id' => 1, 'role' => User::ROLE_OWNER]));
    }

    public function test_card_and_cod_totals_use_receipt_amounts_and_keep_the_same_daily_total(): void
    {
        $this->receipt('card', 875000, [['type' => 'CASHLESS', 'label' => 'Оплата карткою', 'value' => 875000]]);
        $this->receipt('cod', 660200, [['type' => 'CASHLESS', 'label' => 'Післяплата через інтегратор NovaPay', 'value' => 660200]]);
        $this->receipt('cod', 90000, [], status: 'error');
        $this->receipt('card', 80000, [], type: 'service_in');

        $this->getJson('/api/finance/checkbox')->assertOk()
            ->assertJsonPath('receipts.daily_total', 1535200)
            ->assertJsonPath('receipts.payment_breakdown', ['account_cents' => 875000, 'cod_cents' => 660200, 'other_cents' => 0])
            ->assertJsonPath('receipts.payment_receipt_counts', ['account' => 1, 'cod' => 1, 'other' => 0]);
        Http::assertNothingSent();
    }

    public function test_mixed_prepayment_and_cod_and_partial_return_keep_their_receipt_categories(): void
    {
        $sale = $this->receipt('prepay', 100000, [
            ['type' => 'CASHLESS', 'label' => 'Передоплата (картка)', 'value' => 20000],
            ['type' => 'CASHLESS', 'label' => 'Післяплата через інтегратор NovaPay', 'value' => 80000],
        ]);
        $this->receipt('card', 40000, [['type' => 'CASHLESS', 'label' => 'Післяплата через інтегратор NovaPay', 'value' => 40000]], type: 'return');

        // Редагування замовлення не переписує спосіб оплати в уже виданому чеку.
        $sale->order->payment->update(['method' => 'card']);
        $this->getJson('/api/finance/checkbox')->assertOk()
            ->assertJsonPath('receipts.daily_total', 60000)
            ->assertJsonPath('receipts.payment_breakdown', ['account_cents' => 20000, 'cod_cents' => 40000, 'other_cents' => 0])
            ->assertJsonPath('receipts.payment_receipt_counts', ['account' => 1, 'cod' => 2, 'other' => 0]);
    }

    public function test_separate_prepayment_and_cod_receipts_do_not_count_the_whole_order_twice(): void
    {
        $prepay = $this->receipt('prepay', 20000, [['type' => 'CASHLESS', 'label' => 'Передоплата (картка)', 'value' => 20000]]);
        $prepay->order->fiscalReceipts()->create([
            'type' => 'sell', 'status' => 'success', 'total_amount' => 80000,
            'payload_hash' => (string) Str::uuid(),
            'meta' => ['payments' => [['type' => 'CASHLESS', 'label' => 'Післяплата через інтегратор NovaPay', 'value' => 80000]]],
        ]);

        $this->getJson('/api/finance/checkbox')->assertOk()
            ->assertJsonPath('receipts.daily_total', 100000)
            ->assertJsonPath('receipts.payment_breakdown', ['account_cents' => 20000, 'cod_cents' => 80000, 'other_cents' => 0])
            ->assertJsonPath('receipts.payment_receipt_counts', ['account' => 1, 'cod' => 1, 'other' => 0]);
    }

    public function test_old_receipts_fall_back_to_order_payment_without_guessing_ambiguous_prepayments(): void
    {
        $this->receipt('invoice', 10000, []);
        $this->receipt('novapay_cod', 30000, []);
        $this->receipt('prepay', 5000, []);
        $this->receipt(null, 7000, [['type' => 'CASHLESS', 'label' => 'Безготівково', 'value' => 7000]]);

        $this->getJson('/api/finance/checkbox')->assertOk()
            ->assertJsonPath('receipts.payment_breakdown', ['account_cents' => 10000, 'cod_cents' => 30000, 'other_cents' => 12000])
            ->assertJsonPath('receipts.payment_receipt_counts', ['account' => 1, 'cod' => 1, 'other' => 2]);
    }

    public function test_cash_change_and_inconsistent_payments_are_not_reported_as_card_payments(): void
    {
        $cash = $this->receipt('card', 9000, [['type' => 'CASH', 'label' => 'Готівка', 'value' => 10000]]);
        $cash->update(['meta' => array_merge($cash->meta, ['total_rest' => 1000])]);
        $this->receipt('card', 8000, [['type' => 'CASHLESS', 'label' => 'Оплата карткою', 'value' => 5000]]);
        $this->receipt('cod', 3000, [['type' => 'CASHLESS', 'label' => 'NovaPay', 'value' => -3000]]);
        $this->receipt('card', 4000, [['type' => 'CASHLESS', 'label' => 'Оплата карткою', 'value' => 'помилка']]);

        $response = $this->getJson('/api/finance/checkbox')->assertOk()
            ->assertJsonPath('receipts.daily_total', 24000)
            ->assertJsonPath('receipts.payment_breakdown', ['account_cents' => 0, 'cod_cents' => 0, 'other_cents' => 24000])
            ->assertJsonPath('receipts.payment_receipt_counts', ['account' => 0, 'cod' => 0, 'other' => 4]);
        $this->assertSame($response->json('receipts.daily_total'), array_sum($response->json('receipts.payment_breakdown')));
    }

    public function test_cod_return_can_make_its_category_negative(): void
    {
        $this->receipt('card', 10000, [['type' => 'CASHLESS', 'label' => 'Оплата карткою', 'value' => 10000]]);
        $this->receipt('cod', 40000, [['type' => 'CASHLESS', 'label' => 'NovaPay', 'value' => 40000]], type: 'return');

        $this->getJson('/api/finance/checkbox')->assertOk()
            ->assertJsonPath('receipts.daily_total', -30000)
            ->assertJsonPath('receipts.payment_breakdown', ['account_cents' => 10000, 'cod_cents' => -40000, 'other_cents' => 0])
            ->assertJsonPath('receipts.payment_receipt_counts', ['account' => 1, 'cod' => 1, 'other' => 0]);
    }

    public function test_multiple_payment_lines_in_one_category_count_as_one_receipt(): void
    {
        $this->receipt('card', 10000, [
            ['type' => 'CASHLESS', 'label' => 'Оплата карткою', 'value' => 3000],
            ['type' => 'CASHLESS', 'label' => 'Банківський переказ', 'value' => 7000],
        ]);

        $this->getJson('/api/finance/checkbox')->assertOk()
            ->assertJsonPath('receipts.payment_receipt_counts', ['account' => 1, 'cod' => 0, 'other' => 0]);
    }

    public function test_sale_and_full_return_keep_two_receipts_when_the_net_amount_is_zero(): void
    {
        $payment = [['type' => 'CASHLESS', 'label' => 'Оплата карткою', 'value' => 10000]];
        $this->receipt('card', 10000, $payment);
        $this->receipt('card', 10000, $payment, type: 'return');

        $this->getJson('/api/finance/checkbox')->assertOk()
            ->assertJsonPath('receipts.payment_breakdown.account_cents', 0)
            ->assertJsonPath('receipts.payment_receipt_counts', ['account' => 2, 'cod' => 0, 'other' => 0]);
    }

    private function receipt(?string $method, int $amount, array $payments, string $type = 'sell', string $status = 'success'): FiscalReceipt
    {
        $order = Order::create(['order_number' => 'breakdown-'.Str::random(16), 'currency' => 'UAH']);
        if ($method !== null) {
            $order->payment()->create(['method' => $method, 'currency' => 'UAH']);
        }

        return $order->fiscalReceipts()->create([
            'type' => $type, 'status' => $status, 'total_amount' => $amount,
            'payload_hash' => (string) Str::uuid(), 'meta' => ['payments' => $payments],
        ]);
    }
}
