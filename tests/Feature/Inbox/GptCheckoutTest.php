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

}
