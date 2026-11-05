<?php

namespace Tests\Feature\Inbox;

use App\Models\Product;
use App\Services\Ai\Gpt\CheckoutPricing;
use App\Services\Ai\Gpt\CheckoutSession;
use App\Services\Ai\Gpt\OrderTexts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GptCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Models\AiChannelSetting::current()->update(['gpt_enabled' => true]);
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    private function product(string $price = '530.50', int $stock = 5): Product
    {
        $product = Product::create(['title' => 'Домашні пухнасті', 'sale_price' => $price, 'currency' => 'UAH', 'stock_qty' => 999, 'is_active' => true]);
        $product->variants()->create(['size' => '38/39 — 25 см', 'stock_qty' => $stock, 'is_active' => true]);

        return $product;
    }

    private function args(Product $product, ?string $payment = 'cod', array $changes = []): array
    {
        return array_replace(['items' => [['product_id' => $product->id, 'size' => '38/39 — 25 см', 'qty' => 1]],
            'customer_name' => 'Тестовий Отримувач', 'phone' => '+380671234567', 'address' => 'Рівне, Нова пошта 344',
            'payment' => $payment, 'confirm_total' => false], $changes);
    }

    private function checkoutSession(?array $data = null, string $turn = 'turn-1', bool $image = false): CheckoutSession
    {
        return new CheckoutSession(app(CheckoutPricing::class), $data, ['turn_id' => $turn, 'has_image' => $image]);
    }

    public function test_server_calculates_all_payment_modes_in_cents_without_losing_price_precision(): void
    {
        $product = $this->product();
        foreach (['cod' => ['0.00', '530.50'], 'full' => ['530.50', '0.00'], 'deposit' => ['100.00', '430.50']] as $mode => [$now, $later]) {
            $session = $this->checkoutSession();
            $result = $session->run('update_order', $this->args($product, $mode));
            $this->assertArrayNotHasKey('помилка', $result);
            $this->assertSame('530.50', $session->data['total']);
            $this->assertSame($now, $session->data['pay_now']);
            $this->assertSame($later, $session->data['pay_on_delivery']);
            $this->assertSame('530.50', $session->data['items'][0]['line_total']);
        }
        $this->assertSame(5, $product->variants()->first()->stock_qty);
        $tiny = $this->product('0.10');
        $session = $this->checkoutSession();
        $args = $this->args($tiny, 'full');
        $args['items'][0]['qty'] = 3;
        $session->run('update_order', $args);
        $this->assertSame('0.30', $session->data['total']);
    }

    public function test_prepayment_100_is_not_added_on_top_of_order_and_cannot_exceed_total(): void
    {
        foreach (['100.00', '99.99'] as $price) {
            $product = $this->product($price);
            $session = $this->checkoutSession();
            $this->assertArrayHasKey('помилка', $session->run('update_order', $this->args($product, 'deposit')));
            $this->assertNull($session->data);
            $session->run('update_order', $this->args($product, 'full'));
            $this->assertSame($price, $session->data['pay_now']);
            $this->assertSame('0.00', $session->data['pay_on_delivery']);
            $session->run('ask_delivery_details', []);
            $this->assertStringNotContainsString('100 грн передоплати', $session->messages[0]);
        }
    }

    public function test_model_cannot_supply_price_total_or_payment_amount(): void
    {
        $product = $this->product();
        $session = $this->checkoutSession();
        $args = $this->args($product);
        $args['items'][0]['price'] = 1;
        $this->assertArrayHasKey('помилка', $session->run('update_order', $args));
        $args = $this->args($product);
        $args['total'] = 1;
        $this->assertArrayHasKey('помилка', $session->run('update_order', $args));
        $session->run('update_order', $this->args($product, 'deposit'));
        $this->assertArrayHasKey('помилка', $session->run('send_payment_details', ['amount' => 1]));
        $this->assertSame([], $session->messages);
        $this->assertSame('530.50', $session->data['total']);
    }

    public function test_exact_active_variant_and_merged_quantity_are_checked_against_stock(): void
    {
        $product = $this->product(stock: 2);
        $session = $this->checkoutSession();
        $args = $this->args($product);
        $args['items'] = array_fill(0, 3, $args['items'][0]);
        $this->assertArrayHasKey('помилка', $session->run('update_order', $args));
        $args['items'] = array_slice($args['items'], 0, 2);
        $session->run('update_order', $args);
        $this->assertSame(2, $session->data['items'][0]['qty']);
        $this->assertSame('1061.00', $session->data['total']);
        $product->variants()->update(['is_active' => false]);
        $this->assertArrayHasKey('помилка', $session->run('complete_order', []));
        $this->assertFalse($session->completed);
        $product->variants()->update(['is_active' => true]);
        $args = $this->args($product);
        $args['items'][0]['size'] = '38';
        $this->assertArrayHasKey('помилка', $session->run('update_order', $args));
    }

    public function test_missing_ambiguous_inactive_and_foreign_currency_products_are_not_accepted(): void
    {
        $product = $this->product();
        $product->variants()->create(['size' => '38/39 — 25 см', 'stock_qty' => 2, 'is_active' => true]);
        $this->assertArrayHasKey('помилка', $this->checkoutSession()->run('update_order', $this->args($product)));
        $product->variants()->latest('id')->first()->delete();
        foreach ([['is_active' => false], ['is_active' => true, 'currency' => 'USD'], ['currency' => 'UAH', 'sale_price' => null], ['sale_price' => 0]] as $change) {
            $product->update($change);
            $this->assertArrayHasKey('помилка', $this->checkoutSession()->run('update_order', $this->args($product)));
        }
    }

    public function test_product_without_variants_uses_its_own_stock_and_unknown_stock_is_blocked(): void
    {
        $product = Product::create(['title' => 'Без розмірів', 'sale_price' => '250.25', 'currency' => 'UAH', 'stock_qty' => 2, 'is_active' => true]);
        $args = $this->args($product);
        $args['items'][0]['size'] = null;
        $session = $this->checkoutSession();
        $session->run('update_order', $args);
        $this->assertSame('250.25', $session->data['total']);
        $product->update(['stock_qty' => null]);
        $this->assertArrayHasKey('помилка', $session->run('complete_order', []));
    }

    public function test_price_change_requires_confirmation_in_a_later_customer_turn(): void
    {
        $product = $this->product();
        $first = $this->checkoutSession();
        $first->run('update_order', $this->args($product));
        $product->update(['sale_price' => '600.25']);
        $this->assertArrayHasKey('помилка', $first->run('complete_order', []));
        $this->assertSame('600.25', $first->data['total']);
        $this->assertTrue($first->data['needs_confirmation']);
        $args = $this->args($product, 'cod', ['items' => null, 'confirm_total' => true]);
        $first->run('update_order', $args);
        $this->assertArrayHasKey('помилка', $first->run('complete_order', []));
        $next = $this->checkoutSession($first->data, 'turn-2');
        $next->run('update_order', $args);
        $this->assertArrayNotHasKey('помилка', $next->run('complete_order', []));
        $this->assertSame('ready_for_manager', $next->data['status']);
    }

    public function test_payment_details_are_exact_idempotent_and_renewed_when_order_changes(): void
    {
        $product = $this->product();
        $session = $this->checkoutSession();
        $session->run('update_order', $this->args($product, 'deposit'));
        $session->run('send_payment_details', []);
        $texts = OrderTexts::all();
        $this->assertCount(4, $session->messages);
        $this->assertStringContainsString('Решта 430,50 грн', $session->messages[0]);
        $this->assertSame($texts['card_number'], $session->messages[1]);
        $this->assertSame(sprintf($texts['iban_intro'], '100'), $session->messages[2]);
        $this->assertSame($texts['iban_number'], $session->messages[3]);
        $session->run('send_payment_details', []);
        $this->assertCount(4, $session->messages);
        $args = $this->args($product, 'deposit');
        $args['items'][0]['qty'] = 2;
        $session->run('update_order', $args);
        $this->assertSame('Тестовий Отримувач', $session->data['customer_name']);
        $this->assertArrayNotHasKey('payment_request', $session->data);
        $session->run('send_payment_details', []);
        $this->assertCount(8, $session->messages);
        $this->assertStringContainsString('Решта 961 грн', $session->messages[4]);
    }

    public function test_completed_draft_cannot_be_changed_by_later_tools_in_same_turn(): void
    {
        $product = $this->product();
        $session = $this->checkoutSession();
        $session->run('update_order', $this->args($product));
        $session->run('complete_order', []);
        $before = $session->data;
        $session->run('update_order', $this->args($product, 'deposit'));
        $this->assertSame($before, $session->data);
        $this->assertTrue($session->completed);
        $this->assertSame([], $session->messages);
    }

    public function test_prepayment_requires_new_receipt_after_current_details_and_full_payment_keeps_cents(): void
    {
        $product = $this->product();
        $session = $this->checkoutSession(image: true);
        $session->run('update_order', $this->args($product, 'full'));
        $this->assertArrayHasKey('помилка', $session->run('complete_order', []));
        $session->run('send_payment_details', []);
        $this->assertStringContainsString('530,50 грн', $session->messages[2]);
        $this->assertArrayHasKey('помилка', $session->run('complete_order', []));
        $textOnly = $this->checkoutSession($session->data, 'turn-2');
        $this->assertArrayHasKey('помилка', $textOnly->run('complete_order', []));
        $receipt = $this->checkoutSession($session->data, 'turn-2', true);
        $this->assertArrayNotHasKey('помилка', $receipt->run('complete_order', []));
        $this->assertSame('provided_unverified', $receipt->data['receipt']['status']);
    }

    public function test_delivery_form_is_queued_once_and_its_checkpoint_confirms_only_that_message(): void
    {
        $session = $this->checkoutSession();
        $session->run('ask_delivery_details', []);
        $session->run('ask_delivery_details', []);
        $this->assertCount(1, $session->messages);
        $this->assertSame([
            'before' => ['delivery_requested' => null, 'payment_request' => null],
            'checkpoints' => [0 => ['delivery_requested' => true]],
            'invalidated_payment_request' => false,
        ], $session->deliveryState());

        $next = $this->checkoutSession($session->data, 'turn-2');
        $next->run('ask_delivery_details', []);
        $this->assertSame([], $next->messages);
        $this->assertSame([], $next->messageCheckpoints);
        $this->assertTrue($next->deliveryState()['before']['delivery_requested']);
    }

    public function test_payment_details_resume_after_each_successfully_delivered_part_without_duplicates(): void
    {
        $product = $this->product();
        $first = $this->checkoutSession();
        $first->run('update_order', $this->args($product, 'deposit'));
        $first->run('send_payment_details', []);
        $this->assertNull($first->deliveryState()['before']['payment_request']);
        $this->assertCount(4, $first->messageCheckpoints);

        foreach ([1, 2, 3] as $delivered) {
            $partial = $first->data;
            $partial['payment_request'] = $first->messageCheckpoints[$delivered - 1]['payment_request'];
            $this->assertFalse($partial['payment_request']['sent']);
            $this->assertSame($delivered, $partial['payment_request']['messages_sent']);

            $next = $this->checkoutSession($partial, 'turn-2');
            $next->run('send_payment_details', []);
            $this->assertSame(array_slice($first->messages, $delivered), $next->messages);
            $this->assertSame($partial['payment_request'], $next->deliveryState()['before']['payment_request']);
            $this->assertSame('turn-1', $next->data['payment_request']['turn_id']);
            foreach ($next->messageCheckpoints as $index => $checkpoint) {
                $request = $checkpoint['payment_request'];
                $this->assertSame($delivered + $index + 1, $request['messages_sent']);
                $this->assertSame(4, $request['message_count']);
                $this->assertSame($request['messages_sent'] === 4, $request['sent']);
            }
            $this->assertTrue($next->data['payment_request']['sent']);
            $this->assertSame(4, $next->data['payment_request']['messages_sent']);
            $next->run('send_payment_details', []);
            $this->assertCount(4 - $delivered, $next->messages);
        }
    }

    public function test_payment_checkpoints_use_the_common_message_indexes_after_delivery_form(): void
    {
        $session = $this->checkoutSession();
        $session->run('update_order', $this->args($this->product(), 'full'));
        $session->run('ask_delivery_details', []);
        $session->run('send_payment_details', []);
        $this->assertCount(5, $session->messages);
        $this->assertSame(['delivery_requested' => true], $session->messageCheckpoints[0]);
        $this->assertSame(1, $session->messageCheckpoints[1]['payment_request']['messages_sent']);
        $this->assertFalse($session->messageCheckpoints[1]['payment_request']['sent']);
        $this->assertSame(4, $session->messageCheckpoints[4]['payment_request']['messages_sent']);
        $this->assertTrue($session->messageCheckpoints[4]['payment_request']['sent']);
    }

    public function test_partial_payment_delivery_cannot_complete_an_order_even_with_a_new_receipt(): void
    {
        $first = $this->checkoutSession();
        $first->run('update_order', $this->args($this->product(), 'full'));
        $first->run('send_payment_details', []);
        $partial = $first->data;
        $partial['payment_request'] = $first->messageCheckpoints[1]['payment_request'];
        $receipt = $this->checkoutSession($partial, 'turn-2', true);
        $result = $receipt->run('complete_order', []);
        $this->assertArrayHasKey('помилка', $result);
        $this->assertStringContainsString('не повністю', $result['помилка']);
        $this->assertFalse($receipt->completed);
        $this->assertSame('draft', $receipt->data['status']);
        $this->assertArrayNotHasKey('receipt', $receipt->data);
    }

    public function test_changed_payment_signature_starts_all_details_again_with_a_new_turn(): void
    {
        $product = $this->product();
        $first = $this->checkoutSession();
        $first->run('update_order', $this->args($product, 'full'));
        $first->run('send_payment_details', []);
        $partial = $first->data;
        $partial['payment_request'] = $first->messageCheckpoints[1]['payment_request'];
        $next = $this->checkoutSession($partial, 'turn-2');
        $next->run('update_order', $this->args($product, 'deposit'));
        $this->assertArrayNotHasKey('payment_request', $next->data);
        $this->assertTrue($next->deliveryState()['invalidated_payment_request']);
        $next->run('send_payment_details', []);
        $this->assertCount(4, $next->messages);
        $this->assertNotSame($partial['payment_request']['signature'], $next->data['payment_request']['signature']);
        $this->assertSame('turn-2', $next->data['payment_request']['turn_id']);
        $this->assertSame(1, $next->messageCheckpoints[0]['payment_request']['messages_sent']);
        $this->assertStringContainsString('Передоплата — 100 грн', $next->messages[0]);
    }

    public function test_legacy_payment_request_is_not_resent_and_completed_order_clears_pending_flag(): void
    {
        $first = $this->checkoutSession();
        $first->run('update_order', $this->args($this->product(), 'full'));
        $first->run('send_payment_details', []);
        $legacy = $first->data;
        unset($legacy['payment_request']['sent'], $legacy['payment_request']['messages_sent'], $legacy['payment_request']['message_count']);
        $legacy['completion_pending'] = true;
        $receipt = $this->checkoutSession($legacy, 'turn-2', true);
        $receipt->run('send_payment_details', []);
        $this->assertSame([], $receipt->messages);
        $this->assertSame([], $receipt->messageCheckpoints);
        $this->assertArrayNotHasKey('помилка', $receipt->run('complete_order', []));
        $this->assertTrue($receipt->completed);
        $this->assertArrayNotHasKey('completion_pending', $receipt->data);
    }

    public function test_price_refresh_invalidates_partial_details_without_restoring_old_request(): void
    {
        $product = $this->product();
        $first = $this->checkoutSession();
        $first->run('update_order', $this->args($product, 'full'));
        $first->run('send_payment_details', []);
        $partial = $first->data;
        $partial['payment_request'] = $first->messageCheckpoints[0]['payment_request'];
        $product->update(['sale_price' => '600.25']);

        $next = $this->checkoutSession($partial, 'turn-2');
        $this->assertArrayHasKey('помилка', $next->run('send_payment_details', []));
        $this->assertTrue($next->deliveryState()['invalidated_payment_request']);
        $this->assertSame($partial['payment_request'], $next->deliveryState()['before']['payment_request']);
        $this->assertArrayNotHasKey('payment_request', $next->data);
        $this->assertSame([], $next->messages);
        $this->assertSame([], $next->messageCheckpoints);
        $this->assertSame('600.25', $next->data['total']);
    }

    public function test_unfinished_request_with_invalid_cursor_requires_a_final_delivery_checkpoint(): void
    {
        $first = $this->checkoutSession();
        $first->run('update_order', $this->args($this->product(), 'full'));
        $first->run('send_payment_details', []);
        $partial = $first->data;
        $partial['payment_request']['sent'] = false;
        $partial['payment_request']['messages_sent'] = 99;

        $next = $this->checkoutSession($partial, 'turn-2');
        $next->run('send_payment_details', []);
        $this->assertSame([OrderTexts::all()['iban_number']], $next->messages);
        $this->assertCount(1, $next->messageCheckpoints);
        $this->assertTrue($next->messageCheckpoints[0]['payment_request']['sent']);
        $this->assertSame(4, $next->messageCheckpoints[0]['payment_request']['messages_sent']);
    }

    public function test_interrupted_prepaid_completion_accepts_a_text_followup_without_requiring_receipt_again(): void
    {
        foreach (['full', 'deposit'] as $payment) {
            $first = $this->checkoutSession();
            $first->run('update_order', $this->args($this->product(), $payment));
            $first->run('send_payment_details', []);
            $receipt = $this->checkoutSession($first->data, 'turn-2', true);
            $receipt->run('complete_order', []);
            $draft = $receipt->data;
            $draft['status'] = 'draft';
            $draft['completion_pending'] = true;
            unset($draft['finished_at']);

            $followup = $this->checkoutSession($draft, 'turn-3');
            $this->assertArrayNotHasKey('помилка', $followup->run('complete_order', []));
            $this->assertTrue($followup->completed);
            $this->assertSame('ready_for_manager', $followup->data['status']);
            $this->assertSame($draft['receipt'], $followup->data['receipt']);
            $this->assertSame('turn-2', $followup->data['receipt']['turn_id']);
            $this->assertArrayNotHasKey('completion_pending', $followup->data);
            $this->assertSame([], $followup->messages);
        }
    }

    public function test_interrupted_completion_cannot_reuse_receipt_after_selection_price_or_payment_changes(): void
    {
        foreach (['selection', 'price', 'payment'] as $change) {
            $product = $this->product();
            $first = $this->checkoutSession();
            $first->run('update_order', $this->args($product, 'full'));
            $first->run('send_payment_details', []);
            $receipt = $this->checkoutSession($first->data, 'turn-2', true);
            $receipt->run('complete_order', []);
            $draft = $receipt->data;
            $draft['status'] = 'draft';
            $draft['completion_pending'] = true;
            unset($draft['finished_at']);

            $followup = $this->checkoutSession($draft, 'turn-3');
            $args = $this->args($product, $change === 'payment' ? 'deposit' : 'full');
            if ($change === 'selection') $args['items'][0]['qty'] = 2;
            if ($change === 'price') $product->update(['sale_price' => '600.25']);
            $followup->run('update_order', $args);
            $this->assertArrayNotHasKey('receipt', $followup->data);
            $this->assertArrayNotHasKey('payment_request', $followup->data);
            $this->assertArrayHasKey('помилка', $followup->run('complete_order', []));

            if ($change === 'price') {
                $followup = $this->checkoutSession($followup->data, 'turn-4');
                $args['confirm_total'] = true;
                $followup->run('update_order', $args);
            }
            $this->assertArrayNotHasKey('помилка', $followup->run('send_payment_details', []));
            $this->assertArrayHasKey('помилка', $followup->run('complete_order', []));
            $this->assertFalse($followup->completed);
            $this->assertSame('draft', $followup->data['status']);
            $this->assertArrayNotHasKey('receipt', $followup->data);
        }
    }

}
