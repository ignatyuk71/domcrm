<?php

namespace Tests\Feature\GptSandbox;

use App\Models\AiPhoto;
use App\Models\AiPhotoGroup;
use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\User;
use App\Modules\GptSandbox\Infrastructure\CatalogReader;
use App\Modules\GptSandbox\Models\SandboxChat;
use App\Modules\GptSandbox\Models\SandboxSetting;
use App\Modules\GptSandbox\Models\SandboxTurn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class GptCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/settings/gpt-sandbox';
    private array $galleryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->galleryFiles as $file) if (is_file($file)) unlink($file);
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        \App\Models\AiChannelSetting::current()->update(['gpt_enabled' => true]);
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->app->bind(CatalogReader::class, fn () => new CatalogReader(true));
    }

    private function product(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'title' => 'Домашні капці рожеві', 'sale_price' => '530.50', 'currency' => 'UAH', 'stock_qty' => 4,
            'is_active' => true, 'cost_price' => '66666.77', 'weight_g' => 87654,
            'category_id' => Category::firstOrCreate(['name' => 'Домашні'])->id,
            'color_id' => Color::firstOrCreate(['name' => 'Рожевий'])->id,
            'description' => 'Пухнастий верх, відкрита п’ята.',
        ], $attributes));
    }

    private function photo(array $products): AiPhoto
    {
        $group = AiPhotoGroup::create(['name' => 'Домашня лінія', 'ai_description' => '36/37 — 24 см; 38/39 — 25 см.']);
        $group->products()->attach(collect($products)->pluck('id'));
        $path = 'ai-gallery/'.Str::uuid().'.png';
        $photo = AiPhoto::create(['ai_photo_group_id' => $group->id, 'path' => $path]);
        $photo->products()->attach(collect($products)->pluck('id'));
        if (! is_dir(public_path('ai-gallery'))) mkdir(public_path('ai-gallery'), 0775, true);
        $this->galleryFiles[] = public_path($path);
        file_put_contents(public_path($path), UploadedFile::fake()->image('photo.png', 30, 30)->get());

        return $photo;
    }

    private function ownerChat(): SandboxChat
    {
        $owner = User::factory()->create(['role' => User::ROLE_OWNER, 'is_active' => true]);
        $this->actingAs($owner);
        SandboxSetting::create(['user_id' => $owner->id, 'api_key' => 'sk-catalog-test', 'model' => 'gpt-test']);

        return SandboxChat::create(['user_id' => $owner->id]);
    }

    private function toolCall(string $name, array $arguments, string $id): array
    {
        return ['status' => 'completed', 'output' => [[
            'type' => 'reasoning', 'id' => 'rs_'.$id, 'encrypted_content' => 'encrypted-'.$id, 'summary' => [],
        ], [
            'type' => 'function_call', 'id' => 'fc_'.$id, 'call_id' => $id, 'name' => $name,
            'arguments' => json_encode($arguments, JSON_UNESCAPED_UNICODE),
        ]]];
    }

    private function answer(string $text): array
    {
        return ['status' => 'completed', 'output' => [[
            'type' => 'message', 'role' => 'assistant', 'phase' => 'final_answer',
            'content' => [['type' => 'output_text', 'text' => $text]],
        ]]];
    }

    private function send(SandboxChat $chat, string $text = 'Покажіть капці та ціну')
    {
        return $this->postJson(self::BASE.'/chats/'.$chat->id.'/messages', ['request_id' => (string) Str::uuid(), 'text' => $text]);
    }

    public function test_catalog_exposes_only_active_public_fields_and_exact_size_stock(): void
    {
        $product = $this->product(['stock_qty' => 99]);
        $product->variants()->createMany([
            ['size' => '36/37', 'stock_qty' => 2, 'is_active' => true],
            ['size' => '38/39', 'stock_qty' => 0, 'is_active' => true],
            ['size' => '40/41', 'stock_qty' => 88, 'is_active' => false],
        ]);
        $hidden = $this->product(['title' => 'СЛУЖБОВИЙ ТОВАР', 'is_active' => false]);
        $photo = $this->photo([$product]);
        $catalog = app(CatalogReader::class);
        $result = $catalog->product($product->id);
        $this->assertSame('530.50', $result['price']);
        $this->assertSame('UAH', $result['currency']);
        $this->assertSame(2, $result['stock_qty']);
        $this->assertSame([['size' => '36/37', 'stock_qty' => 2], ['size' => '38/39', 'stock_qty' => 0]], $result['sizes']);
        $this->assertSame($photo->id, $result['photos'][0]['id']);
        $this->assertStringContainsString('24 см', $result['groups'][0]['description']);
        $json = json_encode($result);
        foreach (['cost_price', '66666.77', 'weight_g', '87654', 'storage/', 'ai-gallery/'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
        $this->assertArrayHasKey('error', $catalog->product($hidden->id));
        $this->assertSame(1, $catalog->overview()['products']);
    }

    public function test_unicode_filters_budget_and_availability_match_the_same_variant(): void
    {
        $product = $this->product();
        $product->variants()->createMany([
            ['size' => '36/37', 'stock_qty' => 3, 'is_active' => true],
            ['size' => '38/39', 'stock_qty' => 0, 'is_active' => true],
        ]);
        $catalog = app(CatalogReader::class);
        $filters = ['query' => 'ДОМАШНІ', 'color' => 'рожевий', 'max_price' => 531, 'size' => '36/37', 'in_stock_only' => true];
        $this->assertCount(1, $catalog->browse($filters)['products']);
        $this->assertEmpty($catalog->browse(array_merge($filters, ['size' => '38/39']))['products']);
        $this->assertEmpty($catalog->browse(array_merge($filters, ['max_price' => 500]))['products']);
        $this->assertEmpty($catalog->browse(['query' => '%', 'in_stock_only' => false])['products']);
    }

    public function test_stock_without_variants_unknown_stock_and_inactive_variants_are_distinct(): void
    {
        $plain = $this->product(['stock_qty' => 5]);
        $unknown = $this->product(['stock_qty' => null]);
        $inactiveSizes = $this->product(['stock_qty' => 90]);
        $inactiveSizes->variants()->create(['size' => '38', 'stock_qty' => 90, 'is_active' => false]);
        $catalog = app(CatalogReader::class);
        $this->assertTrue($catalog->product($plain->id)['in_stock']);
        $this->assertNull($catalog->product($unknown->id)['in_stock']);
        $this->assertFalse($catalog->product($inactiveSizes->id)['in_stock']);
        $this->assertSame([$plain->id], array_column($catalog->browse(['in_stock_only' => true])['products'], 'id'));
    }

    public function test_size_filter_accepts_catalog_centimeter_labels_without_matching_other_sizes(): void
    {
        $product = $this->product();
        $product->variants()->createMany([
            ['size' => '36/37-24см', 'stock_qty' => 3, 'is_active' => true],
            ['size' => '38/39 - 25см', 'stock_qty' => 0, 'is_active' => true],
            ['size' => '40/41 — 26см', 'stock_qty' => 2, 'is_active' => true],
        ]);
        $catalog = app(CatalogReader::class);
        $this->assertCount(1, $catalog->browse(['size' => '36/37', 'in_stock_only' => true])['products']);
        $this->assertEmpty($catalog->browse(['size' => '38/39', 'in_stock_only' => true])['products']);
        $this->assertCount(1, $catalog->browse(['size' => '38/39', 'in_stock_only' => false])['products']);
        $this->assertCount(1, $catalog->browse(['size' => '40/41', 'in_stock_only' => true])['products']);
        $this->assertEmpty($catalog->browse(['size' => '36', 'in_stock_only' => true])['products']);
    }

    public function test_catalog_paginates_without_silently_dropping_remaining_products(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $this->product(['title' => 'Модель '.$i]);
        }
        $catalog = app(CatalogReader::class);
        $first = $catalog->browse(['in_stock_only' => false]);
        $this->assertCount(20, $first['products']);
        $this->assertTrue($first['has_more']);
        $last = $catalog->browse(['in_stock_only' => false, 'after_id' => $first['next_after_id']]);
        $this->assertCount(1, $last['products']);
        $this->assertFalse($last['has_more']);
    }

    public function test_assortment_context_includes_group_meaning_and_models_without_private_or_volatile_fields(): void
    {
        $adult = $this->product(['title' => 'Teddy Bear', 'description' => 'Закриті домашні капці з аплікацією ведмедика.']);
        $child = $this->product(['title' => 'Дитячий ведмедик', 'category_id' => Category::create(['name' => 'Дитячі'])->id]);
        $hidden = $this->product(['title' => 'ПРИХОВАНА МОДЕЛЬ', 'is_active' => false]);
        $group = AiPhotoGroup::create(['name' => 'Лінійка для дому', 'ai_description' => '<p>Еко-хутро, м’яка устілка. Котики, ведмедики й сердечка.</p>']);
        $group->products()->attach([$adult->id, $hidden->id]);
        $context = app(CatalogReader::class)->assortment();
        $this->assertSame([$adult->id, $child->id], array_column($context['products'], 'id'));
        $this->assertSame([$adult->id], $context['groups'][0]['product_ids']);
        $this->assertStringContainsString('Еко-хутро', $context['groups'][0]['description']);
        $this->assertStringNotContainsString('<p>', $context['groups'][0]['description']);
        $this->assertSame('Закриті домашні капці з аплікацією ведмедика.', $context['products'][0]['description_excerpt']);
        $this->assertFalse($context['has_more']);
        foreach (['cost_price', 'sale_price', 'stock_qty', '66666.77', '87654', 'ПРИХОВАНА МОДЕЛЬ'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($context, JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_api_request_contains_store_prompt_and_keeps_user_message_and_tools(): void
    {
        $chat = $this->ownerChat();
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->answer('Вітаю!'))]);
        $this->send($chat, 'Привіт')->assertOk();
        $request = Http::recorded()[0][0]->data();
        $this->assertStringContainsString('ЗАЛІЗНІ ПРАВИЛА', $request['instructions']);
        $this->assertSame('Привіт', $request['input'][0]['content'][0]['text']);
        $this->assertStringContainsString('система: зараз', $request['input'][0]['content'][1]['text']);
        $this->assertContains('get_product', array_column($request['tools'], 'name'));
        $this->assertContains('complete_order', array_column($request['tools'], 'name'));
        $this->assertDatabaseCount('inbox_messages', 0);
        Http::assertSentCount(1);
    }

    public function test_large_assortment_is_explicitly_incomplete_and_remaining_products_stay_browsable(): void
    {
        $category = Category::create(['name' => 'Категорія']);
        $rows = [];
        for ($i = 0; $i < 200; $i++) {
            $rows[] = ['title' => 'Модель '.$i, 'category_id' => $category->id, 'sale_price' => '100.00', 'is_active' => true];
        }
        DB::table('products')->insert($rows);
        $last = $this->product(['title' => 'Інша лінійка', 'category_id' => Category::create(['name' => 'Окрема категорія'])->id]);
        $group = AiPhotoGroup::create(['name' => 'Окрема лінійка', 'ai_description' => 'Опис для розуміння іншого типу товару.']);
        $group->products()->attach($last->id);
        $catalog = app(CatalogReader::class);
        $context = $catalog->assortment();
        $this->assertCount(200, $context['products']);
        $this->assertTrue($context['has_more']);
        $this->assertSame($group->id, $context['groups'][0]['id']);
        $this->assertStringContainsString('іншого типу', $context['groups'][0]['description']);
        $this->assertSame([$last->id], array_column($catalog->browse(['after_id' => $context['next_after_id']])['products'], 'id'));
        $this->assertCount(2, $catalog->overview()['categories']);
    }

    public function test_shared_size_guide_is_distinct_from_collage_and_regular_product_photos(): void
    {
        $products = [];
        for ($i = 0; $i < 5; $i++) {
            $products[] = $this->product(['title' => 'Модель '.$i]);
        }
        $guide = $this->photo($products);
        $guide->update(['purpose' => 'size_guide']);
        $collage = $this->photo($products);
        $regular = $this->photo([$products[0]]);
        Storage::disk('local')->put('gpt-sandbox/catalog-source.json', json_encode([
            'database' => DB::connection()->getDatabaseName(), 'captured_at' => '2026-10-28T11:26:13+00:00',
            'photo_purposes' => [$guide->id => ['path' => $guide->path, 'purpose' => 'size_guide']],
        ]));
        $catalog = app(CatalogReader::class);
        foreach ($products as $product) {
            $photos = collect($catalog->product($product->id)['photos'])->keyBy('id');
            $this->assertSame('size_guide', $photos[$guide->id]['purpose']);
            $this->assertSame('size_guide', $photos[$guide->id]['kind']);
            $this->assertStringContainsString('Пояснення розміру', $photos[$guide->id]['label']);
            $this->assertSame('collage', $photos[$collage->id]['kind']);
        }
        $this->assertSame('product', $catalog->photos([$regular->id])[0]['kind']);
        $browse = collect($catalog->browse(['in_stock_only' => false])['products'])->keyBy('id');
        $this->assertSame('size_guide', collect($browse[$products[0]->id]['photos'])->firstWhere('id', $guide->id)['kind']);
    }

    public function test_live_gallery_purpose_has_priority_over_local_snapshot_metadata(): void
    {
        // Робоча схема вже має призначення фото.
        try {
            $product = $this->product();
            $guide = $this->photo([$product]);
            $regular = $this->photo([$product]);
            DB::table('ai_photos')->where('id', $guide->id)->update(['purpose' => 'size_guide']);
            Storage::disk('local')->put('gpt-sandbox/catalog-source.json', json_encode([
                'database' => DB::connection()->getDatabaseName(), 'captured_at' => '2026-10-28T11:26:13+00:00',
                'photo_purposes' => [$regular->id => ['path' => $regular->path, 'purpose' => 'size_guide']],
            ]));
            $photos = collect(app(CatalogReader::class)->product($product->id)['photos'])->keyBy('id');
            $this->assertSame('size_guide', $photos[$guide->id]['kind']);
            $this->assertSame('product', $photos[$regular->id]['kind']);
        } finally {
            Storage::disk('local')->delete('gpt-sandbox/catalog-source.json');
        }
    }

    public function test_snapshot_photo_purpose_cannot_apply_to_another_database_or_replaced_file(): void
    {
        $product = $this->product();
        $photo = $this->photo([$product]);
        $source = ['database' => 'other-database.sqlite', 'photo_purposes' => [$photo->id => ['path' => $photo->path, 'purpose' => 'size_guide']]];
        Storage::disk('local')->put('gpt-sandbox/catalog-source.json', json_encode($source));
        $this->assertSame('product', app(CatalogReader::class)->photos([$photo->id])[0]['kind']);
        $source['database'] = DB::connection()->getDatabaseName();
        $source['photo_purposes'][$photo->id]['path'] = 'ai-gallery/replaced.png';
        Storage::disk('local')->put('gpt-sandbox/catalog-source.json', json_encode($source));
        $this->assertSame('product', app(CatalogReader::class)->photos([$photo->id])[0]['kind']);
    }

    public function test_size_question_tool_result_and_reply_keep_the_size_guide_purpose(): void
    {
        $product = $this->product();
        $guide = $this->photo([$product]);
        $guide->update(['purpose' => 'size_guide']);
        Storage::disk('local')->put('gpt-sandbox/catalog-source.json', json_encode([
            'database' => DB::connection()->getDatabaseName(), 'captured_at' => '2026-10-28T11:26:13+00:00',
            'photo_purposes' => [$guide->id => ['path' => $guide->path, 'purpose' => 'size_guide']],
        ]));
        $chat = $this->ownerChat();
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->toolCall('get_product', ['product_id' => $product->id], 'read'))
            ->push($this->toolCall('send_photos', ['photo_ids' => [$guide->id]], 'show'))
            ->push($this->answer('Додаю фото-пояснення заміру.'))]);
        $response = $this->send($chat, "маломірять\r\nможна сітку")->assertOk()
            ->assertJsonPath('turn.gallery_photos.0.id', $guide->id)
            ->assertJsonPath('turn.gallery_photos.0.kind', 'size_guide');
        $this->get($response->json('turn.gallery_photos.0.url'))->assertOk();
        $request = Http::recorded()[1][0]->data();
        $facts = json_decode(collect($request['input'])->firstWhere('type', 'function_call_output')['output'], true);
        $this->assertContains($guide->id, $facts['пояснення_розміру']);
    }

    public function test_tool_loop_shows_gallery_photo_and_replays_complete_tool_history(): void
    {
        $product = $this->product();
        $photo = $this->photo([$product]);
        $chat = $this->ownerChat();
        $before = DB::table('products')->first();
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->toolCall('get_product', ['product_id' => $product->id], 'read'))
            ->push($this->toolCall('send_photos', ['photo_ids' => [$photo->id]], 'show'))
            ->push($this->answer('Рожеві домашні капці — 530,50 грн.'))
            ->push($this->answer('Так, це та сама модель.'))]);
        $response = $this->send($chat)->assertOk()->assertJsonPath('turn.gallery_photos.0.id', $photo->id);
        $this->get($response->json('turn.gallery_photos.0.url'))->assertOk()->assertHeader('Content-Type', 'image/png');
        $requests = Http::recorded()->pluck(0);
        $tools = array_column($requests[0]['tools'], 'name');
        $this->assertSame(['update_order', 'send_payment_details', 'complete_order', 'get_product', 'ask_delivery_details', 'escalate_to_manager', 'send_photos'], $tools);
        foreach ($requests as $request) {
            $this->assertStringContainsString('ОФОРМЛЕННЯ ЗАМОВЛЕННЯ', $request['instructions']);
        }
        $second = $requests[1]->data()['input'];
        $this->assertSame('reasoning', $second[1]['type']);
        $this->assertSame('function_call', $second[2]['type']);
        $this->assertSame('function_call_output', $second[3]['type']);
        $facts = json_decode($second[3]['output'], true);
        $this->assertSame('530,50 грн', $facts['ціна']);
        $this->assertArrayNotHasKey('cost_price', $facts);
        $this->send($chat, 'Це та сама модель?')->assertOk();
        $history = Http::recorded()->last()[0]->data()['input'];
        $this->assertCount(2, array_filter($history, fn ($item) => ($item['type'] ?? '') === 'function_call_output'));
        $this->assertEquals($before, DB::table('products')->first());
        $this->assertDatabaseCount('inbox_messages', 0);
        $this->assertDatabaseCount('ai_runs', 0);
        $this->assertSame(1, count(SandboxTurn::first()->gallery_photos));
    }

    public function test_next_question_reads_updated_prices_and_stock(): void
    {
        $product = $this->product();
        $chat = $this->ownerChat();
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->toolCall('get_product', ['product_id' => $product->id], 'first'))->push($this->answer('530,50 грн.'))
            ->push($this->toolCall('get_product', ['product_id' => $product->id], 'second'))->push($this->answer('Ціна змінилася.'))]);
        $this->send($chat)->assertOk();
        $product->update(['sale_price' => '600.00', 'stock_qty' => 0]);
        $this->send($chat, 'А зараз є?')->assertOk();
        $outputs = collect(Http::recorded()->last()[0]->data()['input'])->where('type', 'function_call_output');
        $updated = json_decode($outputs->last()['output'], true);
        $this->assertSame('600 грн', $updated['ціна']);
        $this->assertSame('немає в наявності', $updated['наявність']);
    }

    public function test_invalid_photo_ids_and_unknown_tools_cannot_change_customer_data(): void
    {
        $product = $this->product();
        $photo = $this->photo([$product]);
        $chat = $this->ownerChat();
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->toolCall('send_photos', ['photo_ids' => [999999]], 'guess'))
            ->push($this->toolCall('delete_order', ['id' => 1], 'unknown'))
            ->push($this->answer('Спочатку уточнімо модель.'))]);
        $this->send($chat)->assertOk()->assertJsonPath('turn.gallery_photos', []);
        $last = collect(Http::recorded()->last()[0]->data()['input'])->where('type', 'function_call_output');
        $this->assertStringContainsString('не знайдено', $last->first()['output']);
        $this->assertStringContainsString('Невідомий', $last->last()['output']);
        $this->assertDatabaseCount('products', 1);
    }

    public function test_gallery_endpoint_enforces_roles_active_links_and_safe_paths(): void
    {
        $product = $this->product();
        $photo = $this->photo([$product]);
        $chat = $this->ownerChat();
        $url = self::BASE.'/catalog/photos/'.$photo->id;
        $owner = auth()->user();
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs(User::factory()->create(['role' => User::ROLE_OPERATOR, 'is_active' => true]))->get($url)->assertForbidden();
        $this->actingAs($owner);
        $product->update(['is_active' => false]);
        $this->get($url)->assertNotFound();
        $product->update(['is_active' => true]);
        $photo->update(['path' => 'ai-gallery/../../.env']);
        $this->get($url)->assertNotFound();
        $photo->update(['path' => 'https://example.com/photo.jpg']);
        $this->get($url)->assertNotFound();
        $this->assertDatabaseCount('gpt_sandbox_chats', 1);
        Http::assertNothingSent();
    }

    public function test_failed_api_after_photo_selection_does_not_commit_partial_gallery_reply(): void
    {
        $product = $this->product();
        $photo = $this->photo([$product]);
        $chat = $this->ownerChat();
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->toolCall('get_product', ['product_id' => $product->id], 'read'))
            ->push($this->toolCall('send_photos', ['photo_ids' => [$photo->id]], 'show'))
            ->push(['error' => ['message' => 'temporary']], 503)]);
        $this->send($chat)->assertStatus(502)->assertJsonPath('turn.status', 'failed')->assertJsonPath('turn.gallery_photos', []);
        $this->assertNull(SandboxTurn::first()->response_output);
        $this->assertSame('Покажіть капці та ціну', SandboxTurn::first()->text);
        $this->assertDatabaseCount('inbox_messages', 0);
    }

    public function test_tool_rounds_are_bounded_and_last_request_disables_tools(): void
    {
        $product = $this->product();
        $chat = $this->ownerChat();
        $sequence = Http::sequence();
        for ($i = 0; $i < 6; $i++) {
            $sequence->push($this->toolCall('get_product', ['product_id' => $product->id], 'call'.$i));
        }
        Http::fake(['api.openai.com/v1/responses' => $sequence]);
        $this->send($chat)->assertStatus(502)->assertJsonPath('turn.status', 'failed');
        Http::assertSentCount(6);
        $this->assertSame('none', Http::recorded()->last()[0]['tool_choice']);
    }

    public function test_photo_claim_without_tool_is_repaired_and_intermediate_price_is_preserved(): void
    {
        $product = $this->product();
        $photo = $this->photo([$product]);
        $chat = $this->ownerChat();
        $call = $this->toolCall('send_photos', ['photo_ids' => [$photo->id]], 'repaired-photo');
        array_unshift($call['output'], $this->answer('Доброго дня! Домашні капці — 531 грн.')['output'][0]);
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->answer('Ось фото.'))->push($call)->push(['status' => 'completed', 'output' => []])]);
        $this->send($chat)->assertOk()->assertJsonPath('turn.gallery_photos.0.id', $photo->id)
            ->assertJsonPath('turn.reply', 'Доброго дня! Домашні капці — 531 грн.');
        $second = Http::recorded()[1][0]['input'];
        $this->assertStringContainsString('інструмент send_photos НЕ викликала', $second[array_key_last($second)]['content'][0]['text']);
        Http::assertSentCount(3);
    }

    public function test_recent_photo_is_not_duplicated_or_sent_for_unnecessary_repair(): void
    {
        $product = $this->product();
        $photo = $this->photo([$product]);
        $chat = $this->ownerChat();
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->toolCall('send_photos', ['photo_ids' => [$photo->id]], 'first'))->push($this->answer('Фото моделі.'))
            ->push($this->toolCall('send_photos', ['photo_ids' => [$photo->id]], 'duplicate'))->push($this->answer('Ось фото вище.'))]);
        $this->send($chat)->assertOk()->assertJsonPath('turn.gallery_photos.0.id', $photo->id);
        $this->send($chat, 'Фото ще раз')->assertOk()->assertJsonPath('turn.gallery_photos', []);
        Http::assertSentCount(4);
        $outputs = collect(Http::recorded()->last()[0]['input'])->where('type', 'function_call_output');
        $this->assertStringContainsString('не дублюй', $outputs->last()['output']);
    }

    public function test_photo_read_in_previous_turn_can_be_shown_immediately_and_is_revalidated(): void
    {
        $product = $this->product();
        $photo = $this->photo([$product]);
        $chat = $this->ownerChat();
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->toolCall('get_product', ['product_id' => $product->id], 'initial-read'))
            ->push($this->answer('Ця модель є.'))
            ->push($this->toolCall('send_photos', ['photo_ids' => [$photo->id]], 'show-known'))
            ->push($this->answer('Показую фото.'))
            ->push($this->toolCall('send_photos', ['photo_ids' => [$photo->id]], 'show-inactive'))
            ->push($this->answer('Фото недоступне.'))]);
        $this->send($chat, 'Є такі?')->assertOk();
        $this->send($chat, 'Покажіть')->assertOk()->assertJsonPath('turn.gallery_photos.0.id', $photo->id);
        $product->update(['is_active' => false]);
        $this->send($chat, 'Ще раз')->assertOk()->assertJsonPath('turn.gallery_photos', []);
        $output = collect(Http::recorded()->last()[0]->data()['input'])->where('type', 'function_call_output')->last();
        $this->assertStringContainsString('не знайдено', $output['output']);
    }
}
