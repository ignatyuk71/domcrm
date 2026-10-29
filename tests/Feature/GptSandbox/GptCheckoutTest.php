<?php

namespace Tests\Feature\GptSandbox;

use App\Models\Product;
use App\Models\User;
use App\Modules\GptSandbox\Application\CheckoutPricing;
use App\Modules\GptSandbox\Application\CheckoutSession;
use App\Modules\GptSandbox\Application\OrderTexts;
use App\Modules\GptSandbox\Models\SandboxChat;
use App\Modules\GptSandbox\Models\SandboxSetting;
use App\Modules\GptSandbox\Models\SandboxTurn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class GptCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/settings/gpt-sandbox';

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

    private function toolCall(string $name, array $arguments = [], ?string $text = null): array
    {
        $output = [];
        if ($text !== null) {
            $output[] = ['type' => 'message', 'role' => 'assistant', 'phase' => 'commentary', 'content' => [['type' => 'output_text', 'text' => $text]]];
        }
        $output[] = ['type' => 'function_call', 'call_id' => (string) Str::uuid(), 'name' => $name,
            'arguments' => json_encode($arguments ?: (object) [], JSON_UNESCAPED_UNICODE)];

        return ['status' => 'completed', 'output' => $output];
    }

    private function answer(string $text = 'Дані прийнято.'): array
    {
        return ['status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]]]]];
    }

    private function chat(): SandboxChat
    {
        $owner = User::factory()->create(['role' => User::ROLE_OWNER, 'is_active' => true]);
        $this->actingAs($owner);
        SandboxSetting::create(['user_id' => $owner->id, 'api_key' => 'sk-checkout-test', 'model' => 'gpt-test']);

        return SandboxChat::create(['user_id' => $owner->id]);
    }

    private function send(SandboxChat $chat, string $text = 'Оформіть замовлення')
    {
        return $this->postJson(self::BASE.'/chats/'.$chat->id.'/messages', ['request_id' => (string) Str::uuid(), 'text' => $text]);
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

    public function test_cod_order_is_private_encrypted_idempotent_and_keeps_final_customer_answer(): void
    {
        $product = $this->product();
        $chat = $this->chat();
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->toolCall('update_order', $this->args($product)))
            ->push($this->toolCall('complete_order', [], 'Так, інші моделі також є.'))
            ->push(['status' => 'completed', 'output' => []])]);
        $request = ['request_id' => (string) Str::uuid(), 'text' => 'При отриманні. А які ще моделі є?'];
        $this->postJson(self::BASE.'/chats/'.$chat->id.'/messages', $request)->assertOk()
            ->assertJsonPath('checkout.status', 'ready_for_manager')->assertJsonPath('checkout.payment', 'cod')
            ->assertJsonPath('checkout.total', '530.50')->assertJsonPath('checkout.pay_on_delivery', '530.50')
            ->assertJsonPath('turn.reply', "Так, інші моделі також є.\n\n".OrderTexts::all()['final_message']);
        $this->postJson(self::BASE.'/chats/'.$chat->id.'/messages', $request)->assertOk();
        Http::assertSentCount(3);
        $this->assertStringNotContainsString('+380671234567', DB::table('gpt_sandbox_chats')->value('checkout_data'));
        $this->assertDatabaseCount('gpt_sandbox_turns', 1);
        $this->assertDatabaseCount('inbox_conversations', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->send($chat, 'Ще питання')->assertUnprocessable();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER, 'is_active' => true]))
            ->postJson(self::BASE.'/chats/'.$chat->id.'/messages', $request)->assertNotFound();
    }

    public function test_deposit_flow_saves_server_balance_and_receipt_without_creating_real_order(): void
    {
        $product = $this->product();
        $chat = $this->chat();
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->toolCall('update_order', $this->args($product, 'deposit')))
            ->push($this->toolCall('send_payment_details'))->push($this->answer(''))
            ->push($this->toolCall('complete_order'))->push($this->answer('Дякую, передаю на обробку 💛'))]);
        $this->send($chat, '100 грн передоплати, решта на пошті')->assertOk()
            ->assertJsonCount(4, 'turn.checkout_messages')->assertJsonPath('checkout.pay_now', '100.00')
            ->assertJsonPath('checkout.pay_on_delivery', '430.50');
        $this->post(self::BASE.'/chats/'.$chat->id.'/messages', ['request_id' => (string) Str::uuid(), 'text' => 'Квитанція',
            'images' => [UploadedFile::fake()->image('receipt.jpg')]])->assertOk()
            ->assertJsonPath('checkout.status', 'ready_for_manager')->assertJsonPath('checkout.receipt.status', 'provided_unverified');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_stock_change_during_final_model_response_cannot_mark_order_ready(): void
    {
        $product = $this->product();
        $chat = $this->chat();
        $calls = 0;
        Http::fake(['api.openai.com/v1/responses' => function () use ($product, &$calls) {
            $calls++;
            if ($calls === 1) return Http::response($this->toolCall('update_order', $this->args($product)));
            if ($calls === 2) return Http::response($this->toolCall('complete_order'));
            $product->variants()->update(['stock_qty' => 0]);
            return Http::response($this->answer('Готово.'));
        }]);
        $this->send($chat)->assertStatus(502)->assertJsonPath('turn.status', 'failed')->assertJsonPath('checkout', null);
        $this->assertNull($chat->fresh()->checkout_data);
        $this->assertNull(SandboxTurn::first()->reply);
    }

    public function test_failed_api_does_not_commit_draft_or_payment_messages(): void
    {
        $product = $this->product();
        $chat = $this->chat();
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->toolCall('update_order', $this->args($product, 'deposit')))
            ->push($this->toolCall('send_payment_details'))->push(['error' => ['message' => 'temporary']], 503)]);
        $this->send($chat, 'Реквізити')->assertStatus(502)->assertJsonPath('turn.status', 'failed')
            ->assertJsonPath('checkout', null)->assertJsonPath('turn.checkout_messages', []);
        $this->assertNull($chat->fresh()->checkout_data);
    }

    public function test_escalation_keeps_working_handover_text(): void
    {
        $chat = $this->chat();
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->toolCall('escalate_to_manager'))->push($this->answer('Зайвий текст'))]);
        $this->send($chat, 'Потрібна людина')->assertOk()
            ->assertJsonPath('turn.reply', OrderTexts::all()['handover'])->assertJsonPath('checkout.status', 'needs_human');
    }
}
