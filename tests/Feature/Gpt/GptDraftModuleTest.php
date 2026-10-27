<?php

namespace Tests\Feature\Gpt;

use App\Models\AiPhoto;
use App\Models\AiPhotoGroup;
use App\Models\Color;
use App\Models\Product;
use App\Models\User;
use App\Modules\Gpt\Application\DraftService;
use App\Modules\Gpt\Infrastructure\CatalogReader;
use App\Modules\Gpt\Jobs\GenerateDraft;
use App\Modules\Gpt\Models\GptRun;
use App\Modules\Gpt\Models\GptSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class GptDraftModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private AiPhotoGroup $group;

    private Product $black;

    private Product $beige;

    private AiPhoto $blackPhoto;

    private AiPhoto $collage;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() === 'sqlite') {
            // Відтворюємо Unicode LOWER із MySQL для українських назв у тестовій SQLite.
            DB::connection()->getPdo()->sqliteCreateFunction('lower', fn ($value) => $value === null ? null : mb_strtolower($value), 1);
        }
        Http::preventStrayRequests();
        Queue::fake();
        $this->owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->actingAs($this->owner);
        $this->group = AiPhotoGroup::create(['name' => 'М’які тапочки', 'ai_description' => 'М’яка підошва, догляд без машинного прання.']);
        $blackColor = Color::create(['name' => 'Чорний']);
        $this->black = Product::create(['title' => 'Тапочки чорні', 'sku' => 'SOFT-BLK', 'color_id' => $blackColor->id,
            'sale_price' => '1290.00', 'cost_price' => '777.00', 'currency' => 'UAH', 'stock_qty' => 999, 'is_active' => true]);
        $this->black->variants()->createMany([
            ['size' => '39', 'sku' => 'SOFT-BLK-39', 'stock_qty' => 2, 'is_active' => true],
            ['size' => '40', 'stock_qty' => 0, 'is_active' => true],
            ['size' => '41', 'stock_qty' => 99, 'is_active' => false],
        ]);
        $this->beige = Product::create(['title' => 'Тапочки бежеві', 'sku' => 'SOFT-BEIGE', 'sale_price' => '1390.00', 'currency' => 'UAH', 'stock_qty' => 4, 'is_active' => true]);
        $this->group->products()->attach([$this->black->id, $this->beige->id]);
        $this->blackPhoto = $this->photo('black.jpg', [$this->black->id]);
        $this->collage = $this->photo('collage.jpg', [$this->black->id, $this->beige->id]);
        $this->photo('unmarked.jpg', []);
    }

    private function photo(string $name, array $ids): AiPhoto
    {
        $photo = $this->group->photos()->create(['path' => 'ai-gallery/'.$name]);
        $photo->products()->attach($ids);

        return $photo;
    }

    private function enable(): void
    {
        $settings = new GptSetting(['enabled' => true, 'api_key' => 'sk-gpt-fixture-secret', 'model' => 'gpt-test', 'version' => 1]);
        // MySQL не відкочує AUTO_INCREMENT; фікстура відтворює ID запису налаштувань.
        $settings->id = 1;
        $settings->save();
    }

    private function createRun(array $extra = []): GptRun
    {
        $response = $this->postJson('/settings/gpt/drafts', array_replace([
            'request_id' => (string) Str::uuid(), 'message' => 'Покажіть чорні в розмірі 39', 'gallery_group_id' => $this->group->id,
        ], $extra))->assertAccepted();

        return GptRun::findOrFail($response->json('run.id'));
    }

    private function answer(array $extra = []): array
    {
        return array_replace(['action' => 'reply', 'message' => 'Ось вибраний варіант. Можу допомогти з оформленням.',
            'group_id' => $this->group->id, 'product_id' => $this->black->id, 'product_ids' => [], 'size' => '39', 'photo_ids' => [$this->blackPhoto->id]], $extra);
    }

    private function response(array $output): array
    {
        return ['id' => 'resp_test', 'status' => 'completed', 'output' => $output,
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20]];
    }

    private function tool(string $name = 'get_product_offer', ?array $args = null): array
    {
        return ['type' => 'function_call', 'call_id' => 'call_test', 'name' => $name,
            'arguments' => json_encode($args ?? ['product_id' => $this->black->id, 'size' => '39'])];
    }

    private function finalResponse(array $extra = []): array
    {
        return $this->response([['type' => 'message', 'role' => 'assistant', 'content' => [
            ['type' => 'output_text', 'text' => json_encode($this->answer($extra), JSON_UNESCAPED_UNICODE)],
        ]]]);
    }

    private function generate(GptRun $run): GptRun
    {
        (new GenerateDraft($run->id))->handle(app(DraftService::class));

        return $run->fresh();
    }

    public function test_routes_are_private_owner_only_and_disabled_by_default(): void
    {
        $this->get('/settings/gpt')->assertOk()->assertSee('crm-settings-gpt')->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/settings/gpt/data')->assertOk()->assertJsonPath('settings.enabled', false)->assertJsonPath('settings.mode', 'drafts');
        $this->assertDatabaseCount('gpt_settings', 0);
        foreach (['operator', 'packer'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get('/settings/gpt')->assertForbidden();
            $this->getJson('/settings/gpt/data')->assertForbidden();
            $this->putJson('/settings/gpt/settings', [])->assertForbidden();
            $this->postJson('/settings/gpt/drafts', [])->assertForbidden();
        }
        $this->actingAs($this->owner);
        $this->owner->update(['is_active' => false]);
        $this->getJson('/settings/gpt/data')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_separate_key_is_encrypted_hidden_and_preserved_when_blank(): void
    {
        $payload = ['enabled' => true, 'model' => 'gpt-test', 'api_key' => 'sk-gpt-private-test', 'version' => 0];
        $this->putJson('/settings/gpt/settings', $payload)->assertOk()->assertJsonPath('settings.has_api_key', true)
            ->assertJsonMissingPath('settings.api_key')->assertJsonPath('settings.version', 1);
        $this->assertNotSame('sk-gpt-private-test', DB::table('gpt_settings')->value('api_key'));
        $this->putJson('/settings/gpt/settings', array_replace($payload, ['api_key' => null, 'version' => 1]))->assertOk();
        $this->assertSame('sk-gpt-private-test', GptSetting::current()->key());
        $this->assertStringNotContainsString('sk-gpt-private-test', $this->getJson('/settings/gpt/data')->getContent());
        $this->putJson('/settings/gpt/settings', $payload)->assertConflict();
        $this->putJson('/settings/gpt/settings', array_replace($payload, ['version' => 2, 'mode' => 'auto']))->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_invalid_settings_do_not_flash_the_secret_into_session(): void
    {
        $this->put('/settings/gpt/settings', ['api_key' => 'sk-private-secret', 'model' => '', 'enabled' => true, 'version' => 0])
            ->assertUnprocessable()->assertSessionMissing('_old_input');
        Http::assertNothingSent();
    }

    public function test_catalog_uses_existing_links_and_excludes_cost_inactive_variants_and_unmarked_photos(): void
    {
        $catalog = app(CatalogReader::class);
        $group = $catalog->describe($this->group->id);
        $this->assertCount(2, $group['photos']);
        $this->assertSame('collage', $group['photos'][1]['kind']);
        $offer = $catalog->offer($this->black->id);
        $this->assertSame(2, $offer['stock_qty']);
        $this->assertCount(2, $offer['variants']);
        $this->assertSame('1290.00', $offer['sale_price']);
        $this->assertArrayNotHasKey('cost_price', $offer);
        $this->assertFalse($catalog->offer($this->black->id, '40')['available']);
        $this->assertFalse($catalog->offer($this->black->id, '41')['available']);
        $this->assertSame($this->group->id, $catalog->search('SOFT-BLK-39')['models'][0]['id']);
        $this->assertSame($this->group->id, $catalog->search('машинного')['models'][0]['id']);
        $this->assertSame(4, $catalog->offer($this->beige->id)['stock_qty']);
        $this->assertSame([], $catalog->search('%')['models']);
    }

    public function test_manual_draft_has_verified_price_photo_and_no_live_side_effects(): void
    {
        $this->enable();
        $run = $this->createRun();
        Queue::assertPushedOn('gpt-drafts', GenerateDraft::class);
        Queue::assertPushed(GenerateDraft::class, fn ($job) => $job->connection === 'database');
        $this->assertDatabaseCount('inbox_messages', 0);
        $this->assertDatabaseCount('orders', 0);
        $beforeProduct = $this->black->fresh()->getAttributes();
        $beforeGroup = $this->group->fresh()->getAttributes();
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->response([$this->tool()]))->push($this->finalResponse())]);
        $run = $this->generate($run);
        $this->assertSame('completed', $run->status, $run->error_message ?? '');
        $this->assertStringContainsString('1290.00 UAH', $run->result['message']);
        $this->assertSame('/ai-gallery/black.jpg', $run->result['photos'][0]['url']);
        $this->assertSame('drafts', $run->result['mode']);
        $this->assertTrue($run->result['needs_review']);
        $this->assertSame($this->black->id, $run->dialogue->memory['product_id']);
        $this->assertSame($beforeProduct, $this->black->fresh()->getAttributes());
        $this->assertSame($beforeGroup, $this->group->fresh()->getAttributes());
        $this->assertDatabaseCount('inbox_messages', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inbox_conversations', 0);
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.openai.com/v1/responses' && $r['store'] === false
            && $r['text']['format']['strict'] === true && ! str_contains($r->body(), 'cost_price'));
        $this->generate($run);
        Http::assertSentCount(2);
    }

    public function test_duplicate_request_is_not_queued_twice_and_conflicting_reuse_is_rejected(): void
    {
        $this->enable();
        $id = (string) Str::uuid();
        $run = $this->createRun(['request_id' => $id]);
        $this->postJson('/settings/gpt/drafts', ['request_id' => $id, 'message' => $run->input_message, 'gallery_group_id' => $this->group->id])
            ->assertOk()->assertJsonPath('run.id', $run->id);
        $this->postJson('/settings/gpt/drafts', ['request_id' => $id, 'message' => 'Інше повідомлення'])->assertConflict();
        Queue::assertPushed(GenerateDraft::class, 1);
        $this->assertDatabaseCount('gpt_runs', 1);
        Http::assertNothingSent();
    }

    public function test_owners_cannot_read_or_continue_another_owners_tests(): void
    {
        $this->enable();
        $run = $this->createRun();
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $this->getJson('/settings/gpt/drafts/'.$run->id)->assertNotFound();
        $this->getJson('/settings/gpt/dialogues/'.$run->gpt_dialogue_id)->assertNotFound();
        $this->postJson('/settings/gpt/drafts', ['request_id' => (string) Str::uuid(), 'message' => 'Привіт', 'dialogue_id' => $run->gpt_dialogue_id])->assertNotFound();
        $this->assertDatabaseCount('gpt_runs', 1);
    }

    public function test_wrong_color_photo_is_rejected_without_losing_message(): void
    {
        $this->enable();
        $wrong = $this->photo('beige.jpg', [$this->beige->id]);
        $run = $this->createRun();
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->response([$this->tool()]))->push($this->finalResponse(['photo_ids' => [$wrong->id]]))]);
        $run = $this->generate($run);
        $this->assertSame('failed', $run->status);
        $this->assertSame('wrong_photo', $run->error_code);
        $this->assertNull($run->result);
        $this->assertSame('Покажіть чорні в розмірі 39', $run->input_message);
        $this->assertNull($run->dialogue->memory);
    }

    public function test_price_is_read_again_after_generation_and_not_taken_from_history(): void
    {
        $this->enable();
        $run = $this->createRun();
        $count = 0;
        Http::fake(function () use (&$count) {
            if (++$count === 1) {
                return Http::response($this->response([$this->tool()]));
            }
            $this->black->update(['sale_price' => '1490.00']);

            return Http::response($this->finalResponse());
        });
        $run = $this->generate($run);
        $this->assertSame('completed', $run->status, $run->error_message ?? '');
        $this->assertStringContainsString('1490.00 UAH', $run->result['message']);
        $this->assertStringNotContainsString('1290.00', $run->result['message']);
    }

    public function test_unverified_numbers_in_model_text_are_not_accepted(): void
    {
        $this->enable();
        $run = $this->createRun();
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->response([$this->tool()]))->push($this->finalResponse(['message' => 'Ціна лише 900 грн.']))]);
        $run = $this->generate($run);
        $this->assertSame('invalid_draft', $run->error_code);
        $this->assertNull($run->result);
    }

    public function test_api_error_is_persistent_redacted_and_does_not_rewrite_memory(): void
    {
        $this->enable();
        $run = $this->createRun();
        Http::fake(['api.openai.com/v1/responses' => Http::response(['error' => ['message' => 'secret sk-gpt-fixture-secret']], 401)]);
        $run = $this->generate($run);
        $this->assertSame('provider_401', $run->error_code);
        $response = $this->getJson('/settings/gpt/drafts/'.$run->id)->assertOk()->assertJsonPath('run.status', 'failed');
        $this->assertStringNotContainsString('sk-gpt-fixture-secret', $response->getContent());
        $this->assertNull($run->dialogue->memory);
    }

    public function test_disabling_gpt_before_worker_starts_prevents_any_api_call(): void
    {
        $this->enable();
        $run = $this->createRun();
        GptSetting::current()->update(['enabled' => false, 'version' => 2]);
        $run = $this->generate($run);
        $this->assertSame('settings_changed', $run->error_code);
        Http::assertNothingSent();
    }

    public function test_only_allowed_read_tools_can_run_and_loop_is_bounded(): void
    {
        $this->enable();
        $run = $this->createRun();
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->response([$this->tool('send_message', ['text' => 'test'])]))
            ->push($this->response([$this->tool()]))->push($this->response([$this->tool()]))
            ->push($this->response([$this->tool()]))->push($this->response([$this->tool()]))]);
        $this->assertSame('invalid_tool', $this->generate($run)->error_code);
        $next = $this->createRun();
        $this->assertSame('round_limit', $this->generate($next)->error_code);
        $this->assertDatabaseCount('inbox_messages', 0);
    }

    public function test_refusal_and_incomplete_responses_are_not_saved_as_success(): void
    {
        $this->enable();
        $run = $this->createRun();
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()->push($this->response([
            ['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'Не можу відповісти.']]],
        ]))->push(['status' => 'incomplete', 'output' => []])]);
        $this->assertSame('refusal', $this->generate($run)->error_code);
        $next = $this->createRun();
        $this->assertSame('incomplete', $this->generate($next)->error_code);
    }

    public function test_catalog_command_is_read_only_and_has_no_api_call(): void
    {
        $this->artisan('gpt:inspect-catalog', ['--group' => $this->group->id])->assertSuccessful();
        $this->assertDatabaseCount('gpt_dialogues', 0);
        $this->assertDatabaseCount('gpt_runs', 0);
        $this->assertDatabaseCount('gpt_settings', 0);
        Http::assertNothingSent();
    }

    public function test_unselected_model_can_be_found_then_described_before_offer_and_draft(): void
    {
        $this->enable();
        $run = $this->createRun(['gallery_group_id' => null]);
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->response([$this->tool('search_models', ['query' => 'SOFT-BLK'])]))
            ->push($this->response([$this->tool('describe_model', ['group_id' => $this->group->id])]))
            ->push($this->response([$this->tool()]))->push($this->finalResponse())]);
        $run = $this->generate($run);
        $this->assertSame('completed', $run->status, $run->error_message ?? '');
        $this->assertSame(['search_products', 'search_models', 'describe_model', 'get_product_offer'], array_column($run->evidence, 'tool'));
        $this->assertSame(4, $run->usage['requests']);
        $this->assertSame(400, $run->usage['input_tokens']);
    }

    public function test_own_history_and_selection_are_used_for_next_manual_draft(): void
    {
        $this->enable();
        $first = $this->createRun();
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->response([$this->tool()]))->push($this->finalResponse())
            ->push($this->response([$this->tool()]))->push($this->finalResponse())]);
        $this->generate($first);
        $second = $this->createRun(['message' => 'А можна ще фото?', 'dialogue_id' => $first->gpt_dialogue_id, 'gallery_group_id' => null]);
        $second = $this->generate($second);
        $this->assertSame('completed', $second->status, $second->error_message ?? '');
        Http::assertSent(fn ($request) => count($request['input']) >= 4
            && $request['input'][0]['content'] === 'Покажіть чорні в розмірі 39'
            && $request['input'][1]['role'] === 'assistant');
        $this->assertDatabaseCount('gpt_dialogues', 1);
        $this->assertDatabaseCount('inbox_messages', 0);
    }

    public function test_inactive_or_foreign_marked_photos_are_not_exposed(): void
    {
        $foreign = Product::create(['title' => 'Сторонній товар', 'is_active' => true]);
        $this->photo('foreign.jpg', [$foreign->id]);
        $this->group->photos()->create(['path' => '../private.jpg'])->products()->attach($this->black->id);
        $this->beige->update(['is_active' => false]);
        $group = app(CatalogReader::class)->describe($this->group->id);
        $this->assertCount(1, $group['photos']);
        $this->assertSame($this->blackPhoto->id, $group['photos'][0]['id']);
    }

    public function test_disabling_settings_during_generation_discards_draft_and_keeps_memory(): void
    {
        $this->enable();
        $run = $this->createRun();
        $calls = 0;
        Http::fake(function () use (&$calls) {
            if (++$calls === 1) {
                return Http::response($this->response([$this->tool()]));
            }
            GptSetting::current()->update(['enabled' => false, 'version' => 2]);

            return Http::response($this->finalResponse());
        });
        $run = $this->generate($run);
        $this->assertSame('settings_changed', $run->error_code);
        $this->assertNull($run->result);
        $this->assertNull($run->dialogue->memory);
    }

    public function test_disabled_settings_stop_before_another_paid_request(): void
    {
        $this->enable();
        $run = $this->createRun();
        Http::fake(function () {
            GptSetting::current()->update(['enabled' => false, 'version' => 2]);

            return Http::response($this->response([$this->tool()]));
        });
        $this->assertSame('settings_changed', $this->generate($run)->error_code);
        Http::assertSentCount(1);
    }

    public function test_size_that_was_never_observed_or_queried_is_rejected(): void
    {
        $this->enable();
        $run = $this->createRun();
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->response([$this->tool()]))->push($this->finalResponse(['size' => '45']))]);
        $this->assertSame('unknown_size', $this->generate($run)->error_code);
    }

    public function test_size_explanation_is_not_returned_as_a_product_color_photo(): void
    {
        if (! Schema::hasColumn('ai_photos', 'purpose')) {
            Schema::table('ai_photos', fn ($table) => $table->string('purpose')->default('product'));
        }
        $guide = $this->photo('size-guide.jpg', [$this->black->id]);
        DB::table('ai_photos')->where('id', $guide->id)->update(['purpose' => 'size_guide']);
        $group = app(CatalogReader::class)->describe($this->group->id);
        $this->assertNotContains($guide->id, array_column($group['photos'], 'id'));
    }

    public function test_color_photos_after_first_forty_images_remain_available(): void
    {
        for ($i = 0; $i < 45; $i++) {
            $this->photo('black-angle-'.$i.'.jpg', [$this->black->id]);
        }
        $late = $this->photo('beige-late.jpg', [$this->beige->id]);
        $group = app(CatalogReader::class)->describe($this->group->id);
        $this->assertContains($late->id, array_column($group['photos'], 'id'));
    }

    public function test_photo_link_is_sent_as_image_input_and_kept_in_chat_history(): void
    {
        $this->enable();
        $message = "Подивіться це фото\nФото: https://example.com/photo.jpg";
        $run = $this->createRun(['message' => $message, 'gallery_group_id' => null]);
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->finalResponse([
            'action' => 'clarify', 'message' => 'Допоможу з вибором.',
            'group_id' => null, 'product_id' => null, 'size' => null, 'photo_ids' => [],
        ]))]);
        $run = $this->generate($run);
        $this->assertSame('completed', $run->status, $run->error_message ?? '');
        $this->assertSame($message, $run->input_message);
        Http::assertSent(fn ($request) => $request['input'][0]['content'][1] === [
            'type' => 'input_image', 'image_url' => 'https://example.com/photo.jpg', 'detail' => 'auto',
        ]);
        Http::assertSentCount(1);
    }

    public function test_direct_catalog_search_finds_slipper_names_without_any_gallery_links(): void
    {
        $red = Color::create(['name' => 'Червоний']);
        $product = Product::create(['title' => 'Домашні тапочки', 'color_id' => $red->id,
            'sku' => 'HOME-RED', 'sale_price' => '850.00', 'currency' => 'UAH', 'cost_price' => '400.00', 'is_active' => true]);
        $product->variants()->create(['size' => '38', 'stock_qty' => 3, 'is_active' => true]);
        $catalog = app(CatalogReader::class);

        foreach (['Привіт! Скільки коштують червоні тапки?', 'Покажіть червоні капці', 'Червоні тапочки 38'] as $query) {
            $found = $catalog->searchProducts($query)['products'];
            $this->assertSame([$product->id], array_column($found, 'id'), $query);
            $this->assertSame('850.00', $found[0]['sale_price']);
            $this->assertSame('Червоний', $found[0]['color']);
            $this->assertSame(3, $found[0]['stock_qty']);
            $this->assertSame('38', $found[0]['variants'][0]['size']);
            $this->assertArrayNotHasKey('cost_price', $found[0]);
        }
    }

    public function test_catalog_search_combines_color_and_size_and_ignores_inactive_variants(): void
    {
        $catalog = app(CatalogReader::class);
        $this->assertSame([$this->black->id], array_column($catalog->searchProducts('Покажіть чорні капці 39')['products'], 'id'));
        $this->assertSame([], $catalog->searchProducts('чорні капці 41')['products']);
        $this->assertSame([$this->black->id], array_column($catalog->searchProducts('soft-blk-39')['products'], 'id'));
        $this->assertSame([$this->black->id], array_column($catalog->searchProducts('Ціна за артикулом SOFT-BLK-39')['products'], 'id'));
        $this->black->update(['is_active' => false]);
        $this->assertSame([], $catalog->searchProducts('SOFT-BLK-39')['products']);
        $this->assertSame([], $catalog->searchProducts('Привіт, дякую!')['products']);
        $this->assertSame([], $catalog->searchProducts('%_\\')['products']);
    }

    public function test_direct_search_is_bounded_and_returns_prices_without_loading_costs(): void
    {
        for ($i = 0; $i < 10; $i++) {
            Product::create(['title' => 'Сандалі '.$i, 'sale_price' => '500.00', 'currency' => 'UAH', 'is_active' => true]);
        }
        $catalog = app(CatalogReader::class);
        DB::enableQueryLog();
        $result = $catalog->searchProducts('сандалі');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(8, $result['products']);
        $this->assertTrue($result['has_more']);
        $this->assertLessThanOrEqual(4, count($queries));
        $this->assertStringNotContainsString('cost_price', json_encode($queries));
        $this->assertSame('500.00', $result['products'][0]['sale_price']);
    }

    public function test_first_response_receives_live_prices_before_any_model_tool_call(): void
    {
        $this->enable();
        $run = $this->createRun(['message' => 'Скільки коштують чорні тапки?', 'gallery_group_id' => null]);
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->finalResponse([
            'group_id' => null, 'photo_ids' => [],
        ]))]);
        $run = $this->generate($run);

        $this->assertSame('completed', $run->status, $run->error_message ?? '');
        $this->assertStringContainsString('1290.00 UAH', $run->result['message']);
        $this->assertSame('39', $run->result['offer']['requested_size']);
        $this->assertSame(['search_products'], array_column($run->evidence, 'tool'));
        Http::assertSent(fn ($request) => str_contains(json_encode($request['input']), '1290.00')
            && ! str_contains($request->body(), 'cost_price'));
        Http::assertSentCount(1);
    }

    public function test_general_assortment_reply_still_displays_verified_prices_for_found_products(): void
    {
        $this->enable();
        $run = $this->createRun(['message' => 'Які у вас є капці та ціни?', 'gallery_group_id' => null]);
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->finalResponse([
            'message' => 'Ціни потрібно уточнити.', 'group_id' => null, 'product_id' => null, 'size' => null, 'photo_ids' => [],
        ]))]);
        $run = $this->generate($run);

        $this->assertSame('completed', $run->status, $run->error_message ?? '');
        $this->assertCount(2, $run->result['offers']);
        $this->assertStringContainsString('1290.00 UAH', $run->result['message']);
        $this->assertStringContainsString('1390.00 UAH', $run->result['message']);
        $this->assertStringNotContainsString('Ціни потрібно уточнити.', $run->result['message']);
    }

    public function test_product_without_gallery_can_be_selected_and_its_price_is_refreshed(): void
    {
        $this->enable();
        $product = Product::create(['title' => 'Вуличні капці', 'sku' => 'STREET-01', 'sale_price' => '900.00',
            'currency' => 'UAH', 'stock_qty' => 2, 'is_active' => true]);
        $run = $this->createRun(['message' => 'Ціна STREET-01?', 'gallery_group_id' => null]);
        Http::fake(function () use ($product) {
            $product->update(['sale_price' => '950.00', 'stock_qty' => 1]);

            return Http::response($this->finalResponse(['group_id' => null, 'product_id' => $product->id, 'size' => null, 'photo_ids' => []]));
        });
        $run = $this->generate($run);

        $this->assertSame('completed', $run->status, $run->error_message ?? '');
        $this->assertStringContainsString('950.00 UAH', $run->result['message']);
        $this->assertSame(1, $run->result['offer']['stock_qty']);
        $this->assertNull($run->result['group']);
        $this->assertSame($product->id, $run->dialogue->memory['product_id']);
    }

    public function test_model_can_search_products_with_prices_in_one_tool_call(): void
    {
        $this->enable();
        $run = $this->createRun(['message' => 'Підкажіть варіанти', 'gallery_group_id' => null]);
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->response([$this->tool('search_products', ['query' => 'чорні тапки', 'size' => '39'])]))
            ->push($this->finalResponse(['group_id' => null, 'photo_ids' => []]))]);
        $run = $this->generate($run);

        $this->assertSame('completed', $run->status, $run->error_message ?? '');
        $this->assertStringContainsString('1290.00 UAH', $run->result['message']);
        $this->assertSame(['search_products'], array_column($run->evidence, 'tool'));
        Http::assertSentCount(2);
    }

    public function test_ungrouped_product_cannot_be_invented_without_a_current_catalog_source(): void
    {
        $this->enable();
        $product = Product::create(['title' => 'Інший товар', 'sale_price' => '100.00', 'currency' => 'UAH', 'is_active' => true]);
        $run = $this->createRun(['message' => 'Покажіть капці', 'gallery_group_id' => null]);
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->finalResponse([
            'group_id' => null, 'product_id' => $product->id, 'size' => null, 'photo_ids' => [],
        ]))]);
        $run = $this->generate($run);
        $this->assertSame('unknown_reference', $run->error_code);
        $this->assertNull($run->result);
    }

    public function test_followup_price_uses_the_selected_product_even_without_a_gallery_group(): void
    {
        $this->enable();
        $first = $this->createRun(['message' => 'Покажіть чорні тапки', 'gallery_group_id' => null]);
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->finalResponse(['group_id' => null, 'photo_ids' => []]))
            ->push($this->finalResponse(['group_id' => null, 'photo_ids' => []]))]);
        $this->generate($first);
        $this->black->update(['sale_price' => '1500.00']);
        $next = $this->createRun(['message' => 'А скільки коштують?', 'dialogue_id' => $first->gpt_dialogue_id, 'gallery_group_id' => null]);
        $next = $this->generate($next);
        $this->assertSame('completed', $next->status, $next->error_message ?? '');
        $this->assertStringContainsString('1500.00 UAH', $next->result['message']);
        $this->assertSame(['get_product_offer'], array_column($next->evidence, 'tool'));
    }

    public function test_catalog_query_can_be_inspected_without_openai_or_gpt_records(): void
    {
        $this->artisan('gpt:inspect-catalog', ['--query' => 'чорні капці'])->expectsOutputToContain('1290.00 UAH')->assertSuccessful();
        $this->assertDatabaseCount('gpt_runs', 0);
        Http::assertNothingSent();
    }

    public function test_clarification_does_not_hide_prices_that_are_already_in_the_catalog(): void
    {
        $this->enable();
        $run = $this->createRun(['message' => 'Ціна чорних капців?', 'gallery_group_id' => null]);
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->finalResponse([
            'action' => 'clarify', 'message' => 'Який розмір вас цікавить?', 'group_id' => null,
            'product_id' => null, 'size' => null, 'photo_ids' => [],
        ]))]);
        $run = $this->generate($run);
        $this->assertSame('completed', $run->status, $run->error_message ?? '');
        $this->assertStringContainsString('1290.00 UAH', $run->result['message']);
    }

    public function test_missing_price_is_not_invented_and_inactive_product_is_not_quoted(): void
    {
        $this->enable();
        $this->black->update(['sale_price' => null]);
        $first = $this->createRun(['message' => 'Ціна чорних тапків?', 'gallery_group_id' => null]);
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->finalResponse([
            'group_id' => null, 'photo_ids' => [],
        ]))]);
        $first = $this->generate($first);
        $this->assertSame('completed', $first->status, $first->error_message ?? '');
        $this->assertStringContainsString('Ціну потрібно уточнити в менеджера.', $first->result['message']);
        $this->assertStringNotContainsString('1290.00', $first->result['message']);

        $next = $this->createRun(['message' => 'Покажіть чорні капці', 'gallery_group_id' => null]);
        Http::fake(function () {
            $this->black->update(['is_active' => false]);

            return Http::response($this->finalResponse(['group_id' => null, 'photo_ids' => []]));
        });
        $next = $this->generate($next);
        $this->assertSame('product_unavailable', $next->error_code);
        $this->assertNull($next->result);
    }
}
