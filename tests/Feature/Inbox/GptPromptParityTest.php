<?php

namespace Tests\Feature\Inbox;

use App\Models\AiPhotoGroup;
use App\Models\AiSetting;
use App\Models\MetaConnection;
use App\Models\Product;
use App\Services\Ai\Gpt\CatalogProtocol;
use App\Services\Ai\Gpt\ConsultantPrompt;
use App\Services\Ai\Gpt\ConversationOpening;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_independent_gpt_prompt_keeps_live_catalog_and_does_not_write_store_settings(): void
    {
        $store = $this->store('Наш магазин', 'Пиши коротко від імені нашого магазину.');
        $product = Product::create(['title' => 'Домашні капці', 'sale_price' => 530, 'is_active' => true, 'cost_price' => 98765]);
        $product->variants()->create(['size' => '38/39', 'stock_qty' => 3, 'is_active' => true]);
        $group = AiPhotoGroup::create(['name' => 'Лінійка', 'ai_description' => 'М’яка устілка; 38/39 — 25 см.']);
        $group->products()->attach($product->id);
        $before = DB::table('ai_settings')->get()->toArray();
        $actual = app(CatalogProtocol::class)->instructions($store->meta_connection_id);
        $this->assertStringContainsString(ConsultantPrompt::VERSION, $actual);
        $this->assertStringContainsString('Наш магазин', $actual);
        $this->assertStringContainsString($store->system_prompt, $actual);
        $this->assertStringNotContainsString(ConversationOpening::SALES_RULE, $actual);
        $this->assertStringNotContainsString('ЗАЛІЗНІ ПРАВИЛА', $actual);
        $this->assertStringContainsString('#'.$product->id, $actual);
        $this->assertStringNotContainsString('98765', $actual);
        $this->assertStringContainsString('100 грн передоплати', $actual);
        $this->assertEquals($before, DB::table('ai_settings')->get()->toArray());
    }

    public function test_tool_names_descriptions_and_optional_fields_preserve_working_contract(): void
    {
        $actual = app(CatalogProtocol::class)->tools();
        $this->assertCount(7, $actual);
        $this->assertEqualsCanonicalizing(['update_order', 'send_payment_details', 'complete_order',
            'get_product', 'ask_delivery_details', 'escalate_to_manager', 'send_photos'], array_column($actual, 'name'));
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

}
