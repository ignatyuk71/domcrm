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

        $black = $this->catalogLine('Вуличні тапки чорні');
        $this->assertStringContainsString("фото:[{$blackPhoto->id}]", $black);
        $this->assertStringContainsString("колаж:{$collage->id}", $black);
        $this->assertStringContainsString('група: Вуличні пухнасті тапки', $black);

        $grey = $this->catalogLine('Вуличні тапки сірі');
        $this->assertStringNotContainsString('фото:[', $grey); // власного фото немає
        $this->assertStringContainsString("колаж:{$collage->id}", $grey);
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
