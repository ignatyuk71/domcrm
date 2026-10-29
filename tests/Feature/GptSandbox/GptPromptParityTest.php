<?php

namespace Tests\Feature\GptSandbox;

use App\Models\AiPhotoGroup;
use App\Models\AiSetting;
use App\Models\InboxConversation;
use App\Models\MetaConnection;
use App\Models\Product;
use App\Models\User;
use App\Modules\GptSandbox\Application\CatalogProtocol;
use App\Modules\GptSandbox\Application\ConversationNotes;
use App\Modules\GptSandbox\Application\PromptBuilder;
use App\Modules\GptSandbox\Models\SandboxChat;
use App\Modules\GptSandbox\Models\SandboxSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class GptPromptParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Models\AiChannelSetting::current()->update(['gpt_enabled' => true]);
    }

    private function store(string $name, string $prompt): AiSetting
    {
        $connection = MetaConnection::create(['page_id' => Str::uuid(), 'page_name' => $name, 'page_access_token' => 'test-only-token']);

        return AiSetting::create(['meta_connection_id' => $connection->id, 'system_prompt' => $prompt, 'enabled' => true]);
    }

    public function test_entire_prompt_and_catalog_match_working_agent_without_changing_its_settings(): void
    {
        $store = $this->store('Наш магазин', 'Пиши коротко від імені нашого магазину.');
        $product = Product::create(['title' => 'Домашні капці', 'sale_price' => 530, 'is_active' => true, 'cost_price' => 98765]);
        $product->variants()->create(['size' => '38/39', 'stock_qty' => 3, 'is_active' => true]);
        $group = AiPhotoGroup::create(['name' => 'Лінійка', 'ai_description' => 'М’яка устілка; 38/39 — 25 см.']);
        $group->products()->attach($product->id);
        $conversation = new InboxConversation(['meta_connection_id' => $store->meta_connection_id]);
        $conversation->setRelation('connection', $store->connection);
        $working = app(\App\Services\Ai\PromptBuilder::class)->buildSystemPrompt($conversation, $store);
        $before = DB::table('ai_settings')->get()->toArray();
        $actual = app(CatalogProtocol::class)->instructions($store->meta_connection_id);
        $expected = implode("\n\n", array_column($working, 'text'));
        $this->assertSame(explode('■ ОФОРМЛЕННЯ ЗАМОВЛЕННЯ', $expected)[0], explode('■ ОФОРМЛЕННЯ ЗАМОВЛЕННЯ', $actual)[0]);
        $this->assertSame(explode('■ ДОСТАВКА / ОПЛАТА.', $expected, 2)[1], explode('■ ДОСТАВКА / ОПЛАТА.', $actual, 2)[1]);
        $this->assertStringContainsString('#'.$product->id, $actual);
        $this->assertStringNotContainsString('98765', $actual);
        $this->assertStringContainsString('100 грн передоплати', $actual);
        $this->assertEquals($before, DB::table('ai_settings')->get()->toArray());
        $this->assertSame(\App\Services\Ai\HistoryBuilder::clockNote(), ConversationNotes::clockNote());
    }

    public function test_tool_names_descriptions_and_optional_fields_preserve_working_contract(): void
    {
        $working = app(\App\Services\Ai\PromptBuilder::class)->toolDefinitions();
        $actual = app(CatalogProtocol::class)->tools();
        $this->assertCount(7, $actual);
        foreach (['get_product', 'ask_delivery_details', 'escalate_to_manager', 'send_photos'] as $name) {
            $this->assertSame(collect($working)->firstWhere('name', $name)['description'], collect($actual)->firstWhere('name', $name)['description']);
        }
        foreach ($actual as $tool) {
            $this->assertTrue($tool['strict']);
            $this->assertFalse($tool['parameters']['additionalProperties']);
            $this->assertSame(array_keys((array) $tool['parameters']['properties']), $tool['parameters']['required']);
        }
        $order = collect($actual)->firstWhere('name', 'update_order')['parameters'];
        $this->assertSame(['cod', 'full', 'deposit', null], $order['properties']['payment']['enum']);
        $this->assertFalse($order['properties']['items']['items']['additionalProperties']);
        $this->assertArrayNotHasKey('price', $order['properties']['items']['items']['properties']);
        $this->assertArrayNotHasKey('amount', (array) collect($actual)->firstWhere('name', 'send_payment_details')['parameters']['properties']);

    }

    public function test_selected_store_supplies_its_saved_prompt_and_never_exposes_other_store_secrets(): void
    {
        $first = $this->store('Перший магазин', 'ПЕРША ІНСТРУКЦІЯ');
        $second = $this->store('Другий магазин', 'ДРУГА ІНСТРУКЦІЯ');
        $owner = User::factory()->create(['role' => User::ROLE_OWNER, 'is_active' => true]);
        $this->actingAs($owner);
        $this->getJson('/settings/gpt-sandbox/state')->assertOk()->assertJsonCount(2, 'stores')->assertDontSee('ПЕРША ІНСТРУКЦІЯ');
        $this->putJson('/settings/gpt-sandbox/settings', ['api_key' => 'sk-test', 'model' => 'gpt-test', 'meta_connection_id' => $second->meta_connection_id])
            ->assertOk()->assertJsonPath('settings.meta_connection_id', $second->meta_connection_id);
        $chat = SandboxChat::create(['user_id' => $owner->id]);
        Http::fake(['api.openai.com/v1/responses' => Http::response(['status' => 'completed', 'output' => [[
            'type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Вітаю!']],
        ]]])]);
        $this->postJson('/settings/gpt-sandbox/chats/'.$chat->id.'/messages', ['request_id' => (string) Str::uuid(), 'text' => 'Привіт'])->assertOk();
        Http::assertSent(fn ($request) => str_contains($request['instructions'], 'ДРУГА ІНСТРУКЦІЯ') && ! str_contains($request['instructions'], 'ПЕРША ІНСТРУКЦІЯ'));
        $this->assertSame($second->meta_connection_id, SandboxSetting::first()->meta_connection_id);
        $this->assertSame('ПЕРША ІНСТРУКЦІЯ', $first->fresh()->system_prompt);
    }
}
