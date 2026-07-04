<?php

namespace Tests\Feature\Inbox;

use App\Models\AiPhoto;
use App\Models\AiPhotoGroup;
use App\Models\InboxContact;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\MetaConnection;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AiAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiGalleryTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role' => User::ROLE_OWNER, 'is_active' => true]);
    }

    private function makeGroupWithPhotos(): array
    {
        $black = Product::create(['title' => 'Вуличні тапки чорні', 'sku' => '6026', 'sale_price' => 450, 'currency' => 'UAH', 'is_active' => true]);
        $grey = Product::create(['title' => 'Вуличні тапки сірі', 'sku' => '6027', 'sale_price' => 450, 'currency' => 'UAH', 'is_active' => true]);

        $group = AiPhotoGroup::create(['name' => 'Вуличні пухнасті тапки']);
        $group->products()->attach([$black->id, $grey->id]);

        $collage = AiPhoto::create(['ai_photo_group_id' => $group->id, 'path' => 'ai-gallery/collage1.jpg', 'sort_order' => 1]);
        $collage->products()->attach([$black->id, $grey->id]);

        $blackPhoto = AiPhoto::create(['ai_photo_group_id' => $group->id, 'path' => 'ai-gallery/black.jpg', 'sort_order' => 2]);
        $blackPhoto->products()->attach([$black->id]);

        return [$group, $collage, $blackPhoto, $black, $grey];
    }

    public function test_group_crud_and_data_endpoint(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->postJson('/settings/ai-gallery/groups', ['name' => 'Домашні тапки'])
            ->assertOk()->assertJson(['ok' => true]);

        $group = AiPhotoGroup::first();
        $product = Product::create(['title' => 'Домашні рожеві', 'sku' => 'H1', 'sale_price' => 530, 'currency' => 'UAH']);

        $this->actingAs($owner)->postJson("/settings/ai-gallery/groups/{$group->id}/products", ['product_id' => $product->id])
            ->assertOk();

        $data = $this->actingAs($owner)->getJson('/settings/ai-gallery/data')->assertOk()->json();
        $this->assertSame('Домашні тапки', $data[0]['name']);
        $this->assertCount(1, $data[0]['products']);
        $this->assertFalse($data[0]['products'][0]['has_photo']);

        $this->actingAs($owner)->patchJson("/settings/ai-gallery/groups/{$group->id}", ['name' => 'Домашні Halluci'])
            ->assertOk();
        $this->assertSame('Домашні Halluci', $group->fresh()->name);

        $this->actingAs($owner)->deleteJson("/settings/ai-gallery/groups/{$group->id}")->assertOk();
        $this->assertDatabaseCount('ai_photo_groups', 0);
        $this->assertDatabaseCount('ai_photo_group_product', 0);
    }

    public function test_photo_marks_and_detach_cleans_ghosts(): void
    {
        $owner = $this->owner();
        [$group, $collage, $blackPhoto, $black, $grey] = $this->makeGroupWithPhotos();

        // Відмітки обмежені товарами групи: чужий id ігнорується
        $alien = Product::create(['title' => 'Піжама', 'sku' => 'PJ1', 'sale_price' => 900, 'currency' => 'UAH']);
        $this->actingAs($owner)->patchJson("/settings/ai-gallery/photos/{$collage->id}", [
            'product_ids' => [$black->id, $alien->id],
        ])->assertOk();
        $this->assertSame([$black->id], $collage->fresh()->products->pluck('id')->all());

        // Прибрали товар з групи → зникають і його відмітки на фото
        $collage->products()->sync([$black->id, $grey->id]);
        $this->actingAs($owner)->deleteJson("/settings/ai-gallery/groups/{$group->id}/products/{$black->id}")->assertOk();
        $this->assertSame([$grey->id], $collage->fresh()->products->pluck('id')->all());
        $this->assertSame(0, $blackPhoto->fresh()->products->count());
    }

    /** Рядок каталогу, що містить goal-підрядок. */
    private function catalogLine(string $needle): string
    {
        $line = collect(explode("\n", app(AiAgentService::class)->buildCatalog()))
            ->first(fn ($l) => str_contains($l, $needle));
        $this->assertNotNull($line, "рядок каталогу не знайдено: {$needle}");
        return $line;
    }

    public function test_internal_line_names_never_reach_the_model(): void
    {
        Product::create(['title' => 'Капці Luxury чорні', 'sku' => 'L1', 'sale_price' => 500, 'currency' => 'UAH', 'is_active' => true]);
        Product::create(['title' => 'Домашні капці з хутра Halluci сині', 'sku' => 'H1', 'sale_price' => 380, 'currency' => 'UAH', 'is_active' => true]);

        $catalog = app(AiAgentService::class)->buildCatalog();

        // Службові назви вирізані, людська частина назви лишилась
        $this->assertStringNotContainsStringIgnoringCase('luxury', $catalog);
        $this->assertStringNotContainsStringIgnoringCase('halluci', $catalog);
        $this->assertStringContainsString('Капці чорні', $catalog);
        $this->assertStringContainsString('Домашні капці з хутра сині', $catalog);
    }

    public function test_catalog_lists_every_active_product(): void
    {
        Product::create(['title' => 'Вуличні пухнасті тапки чорні', 'sku' => 'V1', 'sale_price' => 450, 'currency' => 'UAH', 'is_active' => true]);
        Product::create(['title' => 'Домашні хутряні капці білі', 'sku' => 'D1', 'sale_price' => 530, 'currency' => 'UAH', 'is_active' => true]);

        $catalog = app(AiAgentService::class)->buildCatalog();

        $this->assertStringContainsString('Вуличні пухнасті тапки чорні', $catalog);
        $this->assertStringContainsString('Домашні хутряні капці білі', $catalog);
        $this->assertStringContainsString('450 грн', $catalog);
        $this->assertStringContainsString('НЕМАЄ в наявності', $catalog); // варіантів зі стоком немає
    }

    public function test_catalog_carries_photo_collage_and_group(): void
    {
        [, $collage, $blackPhoto] = $this->makeGroupWithPhotos();

        $catalog = app(AiAgentService::class)->buildCatalog();
        $this->assertStringContainsString('ЛІНІЯ: Вуличні пухнасті тапки', $catalog);
        $this->assertStringContainsString("Колажі лінії: [{$collage->id}]", $catalog);

        $black = $this->catalogLine('Вуличні тапки чорні');
        $this->assertStringContainsString("фото:[{$blackPhoto->id}]", $black);
        $this->assertStringContainsString("колаж:{$collage->id}", $black);

        $grey = $this->catalogLine('Вуличні тапки сірі');
        $this->assertStringNotContainsString('фото:[', $grey); // власного фото немає
        $this->assertStringContainsString("колаж:{$collage->id}", $grey);
    }

    public function test_showcase_descriptions_and_ungrouped_block_in_catalog(): void
    {
        [$group] = $this->makeGroupWithPhotos();
        $group->update(['ai_description' => 'Маломірять — радь на розмір більше. Підошва ЕВА, не ковзає.']);

        // Товар поза лініями
        Product::create(['title' => 'Піжама тепла', 'sku' => 'PJ9', 'sale_price' => 900, 'currency' => 'UAH', 'is_active' => true]);

        $catalog = app(AiAgentService::class)->buildCatalog();

        // Опис лінії йде під заголовком як «Від магазину»
        $this->assertStringContainsString('Від магазину: Маломірять — радь на розмір більше', $catalog);
        // Нерозкладене — в блоці «ІНШІ ТОВАРИ», після ліній
        $this->assertStringContainsString('ІНШІ ТОВАРИ', $catalog);
        $this->assertGreaterThan(
            mb_strpos($catalog, 'ЛІНІЯ: Вуличні пухнасті тапки'),
            mb_strpos($catalog, 'Піжама тепла')
        );
        // Збереження опису через адмінку
        $owner = $this->owner();
        $this->actingAs($owner)->patchJson("/settings/ai-gallery/groups/{$group->id}", ['ai_description' => 'нове знання'])
            ->assertOk();
        $this->assertSame('нове знання', $group->fresh()->ai_description);
        $data = $this->actingAs($owner)->getJson('/settings/ai-gallery/data')->assertOk()->json();
        $this->assertSame('нове знання', $data[0]['ai_description']);
    }

    public function test_showcase_mode_hides_ungrouped_products_completely(): void
    {
        $this->makeGroupWithPhotos();
        Product::create(['title' => 'Піжама тепла', 'sku' => 'PJ8', 'sale_price' => 900, 'currency' => 'UAH', 'is_active' => true]);

        // Режим «вся база»: піжама видима в блоці «інші»
        \App\Models\AiSetting::global()->update(['catalog_mode' => 'all']);
        $this->assertStringContainsString('Піжама тепла', app(AiAgentService::class)->buildCatalog());

        // Режим «лише вітрина»: піжами для ШІ не існує, блоку «інші» нема
        \App\Models\AiSetting::global()->update(['catalog_mode' => 'showcase']);
        $catalog = app(AiAgentService::class)->buildCatalog();
        $this->assertStringNotContainsString('Піжама тепла', $catalog);
        $this->assertStringNotContainsString('ІНШІ ТОВАРИ', $catalog);
        $this->assertStringContainsString('ЛІНІЯ: Вуличні пухнасті тапки', $catalog); // вітрина лишилась

        // Порожня вітрина — чесна заглушка
        \App\Models\AiPhotoGroup::query()->delete();
        $this->assertStringContainsString('вітрина порожня', app(AiAgentService::class)->buildCatalog());

        // Перемикач зберігається через налаштування
        $owner = $this->owner();
        $this->actingAs($owner)->postJson('/settings/ai/save', [
            'model' => 'claude-sonnet-4-6', 'catalog_mode' => 'showcase', 'stores' => [],
        ])->assertOk();
        $this->assertSame('showcase', \App\Models\AiSetting::global()->fresh()->catalog_mode);
    }

    public function test_history_remembers_which_products_were_on_sent_photos(): void
    {
        [, $collage] = $this->makeGroupWithPhotos();

        $conn = MetaConnection::create(['page_id' => 'P9', 'page_name' => 'Shop', 'page_access_token' => 'tok', 'status' => 'active']);
        \App\Models\AiSetting::global()->update(['api_key' => 'sk-ant-test']);
        \App\Models\AiSetting::forConnection($conn->id)->update(['enabled' => true]);
        $contact = InboxContact::create(['meta_connection_id' => $conn->id, 'channel' => 'facebook', 'external_id' => 'U9']);
        $conv = InboxConversation::create(['meta_connection_id' => $conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook']);

        // Розмова завжди починається з клієнта (як у вебхуку)
        InboxMessage::create([
            'inbox_conversation_id' => $conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_hi', 'text' => 'Покажіть вуличні', 'sent_at' => now(),
        ]);

        // Агент надіслав фото-колаж (без тексту)
        InboxMessage::create([
            'inbox_conversation_id' => $conv->id, 'direction' => 'out', 'sender' => 'ai',
            'external_message_id' => 'm_sent_ph', 'text' => null,
            'attachments' => [['type' => 'image', 'url' => url($collage->path)]],
            'sent_at' => now(),
        ]);
        $ask = InboxMessage::create([
            'inbox_conversation_id' => $conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_these', 'text' => 'Яка ціна цих?', 'sent_at' => now(),
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Ці — 450 грн 🙂']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 700, 'output_tokens' => 20],
            ], 200),
            'graph.facebook.com/*' => Http::response(['message_id' => 'm_these_out'], 200),
        ]);

        (new \App\Jobs\AiRespondToMessage($conv->id, $ask->id))->handle(app(AiAgentService::class));

        $body = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.anthropic.com'))
            ->first()[0]->body();

        // Модель бачить, ЩО саме було на надісланому фото — «цих» має адресата.
        // Нотатка СИСТЕМНИМ голосом — щоб модель не копіювала її як власну фразу.
        $this->assertStringContainsString(trim((string) json_encode('система: ти надіслала клієнту фото'), '"'), $body);
        $this->assertStringContainsString(trim((string) json_encode('Вуличні тапки чорні'), '"'), $body);
    }

    public function test_color_with_many_angles_exposes_all_its_photos(): void
    {
        [$group, , $blackPhoto, $black] = $this->makeGroupWithPhotos();

        $angle2 = AiPhoto::create(['ai_photo_group_id' => $group->id, 'path' => 'ai-gallery/black2.jpg', 'sort_order' => 3]);
        $angle2->products()->attach([$black->id]);
        $angle3 = AiPhoto::create(['ai_photo_group_id' => $group->id, 'path' => 'ai-gallery/black3.jpg', 'sort_order' => 4]);
        $angle3->products()->attach([$black->id]);

        $line = $this->catalogLine('Вуличні тапки чорні');
        $this->assertStringContainsString("фото:[{$blackPhoto->id},{$angle2->id},{$angle3->id}]", $line);
    }

    public function test_product_gets_its_own_collage_not_the_first_one(): void
    {
        [$group, $collage1] = $this->makeGroupWithPhotos();

        // Друга пара кольорів лінійки живе на колажі №2
        $bordo = Product::create(['title' => 'Вуличні тапки бордо', 'sku' => '6028', 'sale_price' => 450, 'currency' => 'UAH', 'is_active' => true]);
        $beige = Product::create(['title' => 'Вуличні тапки беж', 'sku' => '6029', 'sale_price' => 450, 'currency' => 'UAH', 'is_active' => true]);
        $group->products()->attach([$bordo->id, $beige->id]);

        $collage2 = AiPhoto::create(['ai_photo_group_id' => $group->id, 'path' => 'ai-gallery/collage2.jpg', 'sort_order' => 5]);
        $collage2->products()->attach([$bordo->id, $beige->id]);

        $this->assertStringContainsString("колаж:{$collage2->id}", $this->catalogLine('Вуличні тапки бордо'));
        $this->assertStringContainsString("колаж:{$collage1->id}", $this->catalogLine('Вуличні тапки чорні'));
    }

    public function test_send_photos_sends_saves_and_dedupes(): void
    {
        [, $collage] = $this->makeGroupWithPhotos();

        $conn = MetaConnection::create(['page_id' => 'P1', 'page_name' => 'Shop', 'page_access_token' => 'tok', 'status' => 'active']);
        $contact = InboxContact::create(['meta_connection_id' => $conn->id, 'channel' => 'facebook', 'external_id' => 'U1']);
        $conv = InboxConversation::create(['meta_connection_id' => $conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook']);

        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'm_ph_1'], 200)]);

        $svc = app(AiAgentService::class);
        $res = $svc->toolSendPhotos($conv, [$collage->id, 999]);

        $this->assertSame('надіслано', $res["фото №{$collage->id}"]);
        $this->assertStringContainsString('не знайдено', $res['фото №999']);
        $this->assertDatabaseHas('inbox_messages', [
            'inbox_conversation_id' => $conv->id,
            'direction' => 'out',
            'sender' => 'ai',
            'external_message_id' => 'm_ph_1',
        ]);
        $msg = InboxMessage::where('sender', 'ai')->first();
        $this->assertSame('image', $msg->attachments[0]['type']);
        $this->assertStringContainsString('ai-gallery/collage1.jpg', $msg->attachments[0]['url']);

        // Повторна спроба того ж фото → пропущено, нове повідомлення не зʼявляється
        $res2 = $svc->toolSendPhotos($conv, [$collage->id]);
        $this->assertStringContainsString('пропущено', $res2["фото №{$collage->id}"]);
        $this->assertSame(1, InboxMessage::where('sender', 'ai')->count());
    }

    public function test_outgoing_save_is_idempotent_when_echo_won_the_race(): void
    {
        [, $collage] = $this->makeGroupWithPhotos();

        $conn = MetaConnection::create(['page_id' => 'P3', 'page_name' => 'Shop', 'page_access_token' => 'tok', 'status' => 'active']);
        $contact = InboxContact::create(['meta_connection_id' => $conn->id, 'channel' => 'facebook', 'external_id' => 'U3']);
        $conv = InboxConversation::create(['meta_connection_id' => $conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook']);

        // Echo-вебхук випередив наш send і вже записав це повідомлення як 'agent'
        // (інший url, щоб не спрацював url-дедуп і фото реально «відправилось»).
        InboxMessage::create([
            'inbox_conversation_id' => $conv->id,
            'direction' => 'out',
            'sender' => 'agent',
            'external_message_id' => 'm_echo_first',
            'attachments' => [['type' => 'image', 'url' => 'https://x/echo.jpg']],
            'sent_at' => now(),
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'm_echo_first'], 200)]);

        $res = app(AiAgentService::class)->toolSendPhotos($conv, [$collage->id]);

        // Не впало, фото надіслане, дубля немає, відправника виправлено на 'ai'.
        $this->assertSame('надіслано', $res["фото №{$collage->id}"]);
        $this->assertSame(1, InboxMessage::where('external_message_id', 'm_echo_first')->count());
        $this->assertSame('ai', InboxMessage::where('external_message_id', 'm_echo_first')->first()->sender);
    }

    public function test_send_photos_caps_at_three(): void
    {
        [$group] = $this->makeGroupWithPhotos();
        $extra = [];
        foreach (range(3, 6) as $i) {
            $extra[] = AiPhoto::create(['ai_photo_group_id' => $group->id, 'path' => "ai-gallery/p{$i}.jpg", 'sort_order' => $i]);
        }

        $conn = MetaConnection::create(['page_id' => 'P2', 'page_name' => 'Shop', 'page_access_token' => 'tok', 'status' => 'active']);
        $contact = InboxContact::create(['meta_connection_id' => $conn->id, 'channel' => 'facebook', 'external_id' => 'U2']);
        $conv = InboxConversation::create(['meta_connection_id' => $conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook']);

        // Кожна відправка отримує унікальний mid (як у живого Meta)
        $n = 0;
        Http::fake(function () use (&$n) {
            return Http::response(['message_id' => 'm_x_' . (++$n)], 200);
        });

        $res = app(AiAgentService::class)->toolSendPhotos($conv, collect($extra)->pluck('id')->all());

        $this->assertArrayHasKey('увага', $res);
        $this->assertSame(3, InboxMessage::where('sender', 'ai')->count());
    }

    public function test_operator_cannot_access_gallery_settings(): void
    {
        $operator = User::factory()->create(['role' => User::ROLE_OPERATOR, 'is_active' => true]);
        $this->actingAs($operator)->get('/settings/ai-gallery')->assertForbidden();
    }

    public function test_reply_to_our_collage_tells_model_which_products_it_shows(): void
    {
        [, $collage] = $this->makeGroupWithPhotos();

        $conn = MetaConnection::create(['page_id' => 'P_QR', 'page_name' => 'Shop', 'page_access_token' => 'tok', 'status' => 'active']);
        \App\Models\AiSetting::global()->update(['api_key' => 'sk-ant-test']);
        \App\Models\AiSetting::forConnection($conn->id)->update(['enabled' => true]);
        $contact = InboxContact::create(['meta_connection_id' => $conn->id, 'channel' => 'facebook', 'external_id' => 'U_QR']);
        $conv = InboxConversation::create(['meta_connection_id' => $conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook']);

        InboxMessage::create([
            'inbox_conversation_id' => $conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_q0', 'text' => 'Покажіть вуличні', 'sent_at' => now(),
        ]);
        // Наш колаж, на який клієнт відповість цитатою
        InboxMessage::create([
            'inbox_conversation_id' => $conv->id, 'direction' => 'out', 'sender' => 'ai',
            'external_message_id' => 'm_collage_q', 'text' => null,
            'attachments' => [['type' => 'image', 'url' => url($collage->path)]],
            'sent_at' => now(),
        ]);
        $ask = InboxMessage::create([
            'inbox_conversation_id' => $conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_q1', 'text' => 'Покажіть мені коричневі',
            'context' => ['type' => 'reply', 'mid' => 'm_collage_q'],
            'sent_at' => now(),
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Зараз покажу 🤎']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 700, 'output_tokens' => 15],
            ], 200),
            'graph.facebook.com/*' => Http::response(['message_id' => 'm_qr_out'], 200),
        ]);

        (new \App\Jobs\AiRespondToMessage($conv->id, $ask->id))->handle(app(AiAgentService::class));

        $body = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.anthropic.com'))
            ->first()[0]->body();

        // Модель знає, що цитований колаж містить наші товари
        $this->assertStringContainsString(trim((string) json_encode('у відповідь на наше фото; на ньому товари'), '"'), $body);
        $this->assertStringContainsString(trim((string) json_encode('Вуличні тапки чорні'), '"'), $body);
    }

    public function test_story_reply_context_feeds_vision_and_exact_match(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD недоступний');
        }

        // Наше фото в галереї з товаром (реальний PNG для відбитка)
        $product = Product::create(['title' => 'Капці для вулиці ЧОРНИЙ', 'sku' => '6026B', 'sale_price' => 530, 'currency' => 'UAH', 'is_active' => true]);
        $group = AiPhotoGroup::create(['name' => 'Вуличні']);
        $group->products()->attach($product->id);
        $img = imagecreatetruecolor(40, 30);
        imagefilledrectangle($img, 0, 0, 39, 29, imagecolorallocate($img, 20, 20, 25));
        imagefilledellipse($img, 20, 15, 24, 16, imagecolorallocate($img, 230, 230, 235));
        $dir = public_path('ai-gallery');
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        imagepng($img, $dir . '/test-story-src.png');
        imagedestroy($img);
        $photo = AiPhoto::create(['ai_photo_group_id' => $group->id, 'path' => 'ai-gallery/test-story-src.png', 'sort_order' => 1]);
        $photo->products()->attach($product->id);

        $conn = MetaConnection::create(['page_id' => 'P_ST', 'page_name' => 'Shop', 'page_access_token' => 'tok', 'status' => 'active']);
        \App\Models\AiSetting::global()->update(['api_key' => 'sk-ant-test']);
        \App\Models\AiSetting::forConnection($conn->id)->update(['enabled' => true]);
        $contact = InboxContact::create(['meta_connection_id' => $conn->id, 'channel' => 'instagram', 'external_id' => 'U_ST']);
        $conv = InboxConversation::create(['meta_connection_id' => $conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'instagram']);

        // Вхідне: «можна замовити?» як відповідь на сторіс (медіа вже скачане вебхуком)
        $storyBytes = (string) file_get_contents($dir . '/test-story-src.png');
        $localDir = public_path('inbox-context');
        if (!is_dir($localDir)) { @mkdir($localDir, 0755, true); }
        file_put_contents($localDir . '/test-story-ctx.png', $storyBytes);
        $msg = InboxMessage::create([
            'inbox_conversation_id' => $conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_story_q', 'text' => 'Доброго ранку, можна замовити?',
            'context' => ['type' => 'story', 'url' => 'https://cdn.expired/story.jpg', 'local' => 'inbox-context/test-story-ctx.png'],
            'sent_at' => now(),
        ]);

        Http::fake(function ($request) use ($storyBytes) {
            if (str_contains($request->url(), 'inbox-context/test-story-ctx.png')) {
                return Http::response($storyBytes, 200, ['Content-Type' => 'image/png']);
            }
            if (str_contains($request->url(), 'api.anthropic.com')) {
                return Http::response([
                    'content' => [['type' => 'text', 'text' => 'Так! Це наші чорні вуличні — 530 грн 🖤']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 1600, 'output_tokens' => 30],
                ], 200);
            }
            return Http::response(['message_id' => 'm_story_out'], 200);
        });

        (new \App\Jobs\AiRespondToMessage($conv->id, $msg->id))->handle(app(AiAgentService::class));

        $body = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.anthropic.com'))
            ->first()[0]->body();

        // Модель отримала: image-блок сторіс + примітку про сторіс + ТОЧНИЙ збіг з товаром
        $this->assertStringContainsString('"type":"image"', $body);
        $this->assertStringContainsString(trim((string) json_encode('клієнт відповів на нашу СТОРІС'), '"'), $body);
        $this->assertStringContainsString(trim((string) json_encode('Капці для вулиці ЧОРНИЙ'), '"'), $body);
        $this->assertDatabaseHas('inbox_messages', ['text' => 'Так! Це наші чорні вуличні — 530 грн 🖤']);

        @unlink($dir . '/test-story-src.png');
        @unlink($localDir . '/test-story-ctx.png');
    }

    public function test_complete_order_marks_chat_mutes_ai_and_sends_final_text(): void
    {
        [, , , $black] = $this->makeGroupWithPhotos();

        $conn = MetaConnection::create(['page_id' => 'P_CO', 'page_name' => 'Shop', 'page_access_token' => 'tok', 'status' => 'active']);
        \App\Models\AiSetting::global()->update(['api_key' => 'sk-ant-test', 'model' => 'claude-sonnet-4-6']);
        \App\Models\AiSetting::forConnection($conn->id)->update(['enabled' => true, 'system_prompt' => 'Ти продавець.']);
        $contact = InboxContact::create(['meta_connection_id' => $conn->id, 'channel' => 'facebook', 'external_id' => 'U_CO']);
        $conv = InboxConversation::create(['meta_connection_id' => $conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook']);
        $incoming = InboxMessage::create([
            'inbox_conversation_id' => $conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_pay', 'text' => 'При отриманні', 'sent_at' => now(),
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push([
                    'content' => [[
                        'type' => 'tool_use', 'id' => 'tu_co',
                        'name' => 'complete_order',
                        'input' => [
                            'items' => [['title' => 'Вуличні тапки', 'color' => 'чорні', 'size' => '36/37', 'qty' => 1]],
                            'customer_name' => 'Тест Клієнт', 'phone' => '0961112233', 'address' => 'Київ, №1', 'payment' => 'при отриманні',
                        ],
                    ]],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 400, 'output_tokens' => 40],
                ], 200)
                ->push([
                    'content' => [['type' => 'text', 'text' => 'Дякуємо! Ваше замовлення прийнято 💛 Вуличні тапки, чорні, 36/37, оплата при отриманні. Зранку оформимо і напишемо вам тут 🙏']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 450, 'output_tokens' => 45],
                ], 200),
            'graph.facebook.com/*' => Http::response(['message_id' => 'm_co_out'], 200),
        ]);

        (new \App\Jobs\AiRespondToMessage($conv->id, $incoming->id))->handle(app(AiAgentService::class));

        $conv->refresh();
        // Позначка стоїть, бот у розмові вимкнений
        $status = \App\Models\ChatStatus::where('code', 'ai_order')->first();
        $this->assertNotNull($status);
        $this->assertSame($status->id, $conv->chat_status_id);
        $this->assertFalse((bool) $conv->ai_enabled);
        // Фінальне повідомлення пішло — РІВНО текст із конфігу (не від моделі)
        $this->assertDatabaseHas('inbox_messages', [
            'sender' => 'ai',
            'text' => AiAgentService::orderTexts()['final_message'],
        ]);

        // Наступне повідомлення клієнта — бот мовчить (розмова в людей)
        $next = InboxMessage::create([
            'inbox_conversation_id' => $conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_after_co', 'text' => 'А коли відправите?', 'sent_at' => now(),
        ]);
        (new \App\Jobs\AiRespondToMessage($conv->id, $next->id))->handle(app(AiAgentService::class));
        $this->assertDatabaseHas('ai_runs', ['inbox_message_id' => $next->id, 'status' => 'skipped_conversation_off']);
    }

    public function test_agent_sends_photo_then_final_text_in_tool_loop(): void
    {
        [, $collage] = $this->makeGroupWithPhotos();

        $conn = MetaConnection::create(['page_id' => 'P3', 'page_name' => 'Shop', 'page_access_token' => 'tok', 'status' => 'active']);
        \App\Models\AiSetting::global()->update(['api_key' => 'sk-ant-test', 'model' => 'claude-sonnet-4-6']);
        \App\Models\AiSetting::forConnection($conn->id)->update(['enabled' => true, 'system_prompt' => 'Ти продавець.']);

        $contact = InboxContact::create(['meta_connection_id' => $conn->id, 'channel' => 'facebook', 'external_id' => 'U3']);
        $conv = InboxConversation::create(['meta_connection_id' => $conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook']);
        $incoming = InboxMessage::create([
            'inbox_conversation_id' => $conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_q1', 'text' => 'Можна фото тапок?', 'sent_at' => now(),
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push([
                    'content' => [[
                        'type' => 'tool_use', 'id' => 'tu_ph',
                        'name' => 'send_photos', 'input' => ['photo_ids' => [$collage->id]],
                    ]],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 300, 'output_tokens' => 30],
                ], 200)
                ->push([
                    'content' => [['type' => 'text', 'text' => 'Надіслав фото! Який колір подобається?']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 350, 'output_tokens' => 25],
                ], 200),
            'graph.facebook.com/*' => Http::sequence()
                ->push(['message_id' => 'm_photo_1'], 200)
                ->push(['message_id' => 'm_text_1'], 200),
        ]);

        (new \App\Jobs\AiRespondToMessage($conv->id, $incoming->id))->handle(app(AiAgentService::class));

        $aiMessages = InboxMessage::where('sender', 'ai')->orderBy('id')->get();
        $this->assertCount(2, $aiMessages);
        $this->assertSame('image', $aiMessages[0]->attachments[0]['type']);
        $this->assertSame('Надіслав фото! Який колір подобається?', $aiMessages[1]->text);

        $run = \App\Models\AiRun::where('status', 'replied')->latest('id')->first();
        $this->assertSame('send_photos', $run->tools_called[0]['tool'] ?? null);
    }
}
