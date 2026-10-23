<?php

namespace Tests\Feature\Inbox;

use App\Jobs\AiRespondToMessage;
use App\Models\AiPhoto;
use App\Models\AiPhotoGroup;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\InboxContact;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\MetaConnection;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AgentTools;
use App\Services\Ai\AiAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiSizeGuideDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function makeGallery(bool $withProductPhotos = true): array
    {
        $products = [];
        foreach ([
            'pink' => ['Ведмедики — рожеві', 'SG-TEDDY-PINK'],
            'brown' => ['Ведмедики — коричневі', 'SG-TEDDY-BROWN'],
            'cat' => ['Котики — сірі', 'SG-CAT-GREY'],
        ] as $key => [$title, $sku]) {
            $products[$key] = Product::create([
                'title' => $title, 'sku' => $sku, 'sale_price' => 380,
                'currency' => 'UAH', 'is_active' => true,
            ]);
        }

        $group = AiPhotoGroup::create(['name' => 'Жіночі теплі домашні капці']);
        $group->products()->attach(collect($products)->pluck('id')->all());
        $unrelated = Product::create([
            'title' => 'Вуличні капці — чорні', 'sku' => 'SG-OUTDOOR-BLACK', 'sale_price' => 530,
            'currency' => 'UAH', 'is_active' => true,
        ]);

        // Пояснення йде першим: порядок не повинен перетворювати його на колаж.
        $guide = AiPhoto::create([
            'ai_photo_group_id' => $group->id, 'path' => 'ai-gallery/teddy-sizing-36-37.png',
            'sort_order' => 0, 'purpose' => AiPhoto::PURPOSE_SIZE_GUIDE,
        ]);
        $guide->products()->attach(collect($products)->pluck('id')->all());

        $collage = null;
        $pinkPhoto = null;
        if ($withProductPhotos) {
            $collage = AiPhoto::create([
                'ai_photo_group_id' => $group->id, 'path' => 'ai-gallery/warm-slippers-collage.jpg',
                'sort_order' => 1,
            ]);
            $collage->products()->attach(collect($products)->pluck('id')->all());
            $pinkPhoto = AiPhoto::create([
                'ai_photo_group_id' => $group->id, 'path' => 'ai-gallery/teddy-pink.jpg',
                'sort_order' => 2,
            ]);
            $pinkPhoto->products()->attach($products['pink']->id);
        }

        return compact('products', 'group', 'guide', 'collage', 'pinkPhoto', 'unrelated');
    }

    private function makeConversation(): InboxConversation
    {
        $connection = MetaConnection::create([
            'page_id' => 'P_SIZE_GUIDE', 'page_name' => 'Shop',
            'page_access_token' => 'tok', 'status' => 'active',
        ]);
        AiSetting::global()->update(['api_key' => 'sk-ant-test', 'model' => 'claude-sonnet-4-6']);
        AiSetting::forConnection($connection->id)->update(['enabled' => true, 'system_prompt' => 'Ти продавець.']);

        $contact = InboxContact::create([
            'meta_connection_id' => $connection->id, 'channel' => 'facebook', 'external_id' => 'U_SIZE_GUIDE',
        ]);

        return InboxConversation::create([
            'meta_connection_id' => $connection->id, 'inbox_contact_id' => $contact->id,
            'channel' => 'facebook', 'ai_enabled' => true,
        ]);
    }

    public function test_size_guide_tool_call_sends_the_image_and_persists_the_explanation(): void
    {
        $gallery = $this->makeGallery();
        $guide = $gallery['guide'];
        $conversation = $this->makeConversation();
        $incoming = InboxMessage::create([
            'inbox_conversation_id' => $conversation->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_sizing_question', 'text' => 'Як заміряти ці Ведмедики до бортика?',
            'sent_at' => now(),
        ]);
        $explanation = 'На зображенні приклад розміру 36/37: до бортика приблизно 23,3 см, разом із ним — 24 см.';

        // Перевіряємо виконання отриманого tool-use, а не якість рішення замоканої моделі.
        Http::preventStrayRequests();
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push([
                    'content' => [[
                        'type' => 'tool_use', 'id' => 'tu_size_guide', 'name' => 'send_photos',
                        'input' => ['photo_ids' => [$guide->id]],
                    ]],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 300, 'output_tokens' => 30],
                ], 200)
                ->push([
                    'content' => [['type' => 'text', 'text' => $explanation]],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 350, 'output_tokens' => 40],
                ], 200),
            'graph.facebook.com/*' => Http::sequence()
                ->push(['message_id' => 'm_size_guide_image'], 200)
                ->push(['message_id' => 'm_size_guide_text'], 200),
        ]);

        (new AiRespondToMessage($conversation->id, $incoming->id))->handle(app(AiAgentService::class));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/P_SIZE_GUIDE/messages')
            && ($request['recipient']['id'] ?? null) === 'U_SIZE_GUIDE'
            && ($request['message']['attachment']['type'] ?? null) === 'image'
            && ($request['message']['attachment']['payload']['url'] ?? null) === $guide->url());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/P_SIZE_GUIDE/messages')
            && ($request['message']['text'] ?? null) === $explanation);

        $outgoing = $conversation->messages()->where('direction', 'out')->orderBy('id')->get();
        $this->assertCount(2, $outgoing);
        $this->assertSame('ai', $outgoing[0]->sender);
        $this->assertSame('m_size_guide_image', $outgoing[0]->external_message_id);
        $this->assertCount(1, $outgoing[0]->attachments);
        $this->assertSame('image', $outgoing[0]->attachments[0]['type']);
        $this->assertSame($guide->url(), $outgoing[0]->attachments[0]['url']);
        $this->assertSame($explanation, $outgoing[1]->text);

        $run = AiRun::where('inbox_message_id', $incoming->id)->firstOrFail();
        $this->assertSame('replied', $run->status);
        $this->assertSame('send_photos', $run->tools_called[0]['tool']);
        $this->assertSame([$guide->id], $run->tools_called[0]['input']['photo_ids']);
    }

    public function test_recent_size_guide_attachment_is_not_sent_twice(): void
    {
        $guide = $this->makeGallery()['guide'];
        $conversation = $this->makeConversation();
        InboxMessage::create([
            'inbox_conversation_id' => $conversation->id, 'direction' => 'out', 'sender' => 'ai',
            'external_message_id' => 'm_existing_size_guide',
            'attachments' => [['type' => 'image', 'url' => $guide->url()]], 'sent_at' => now(),
        ]);

        Http::fake();
        $result = app(AgentTools::class)->runTool('send_photos', ['photo_ids' => [$guide->id]], $conversation);

        $this->assertStringContainsString('пропущено', $result["фото №{$guide->id}"]);
        Http::assertNothingSent();
        $this->assertSame(1, $conversation->messages()->where('direction', 'out')->count());
    }

    public function test_get_product_separates_shared_group_size_guides_from_unrelated_products(): void
    {
        $gallery = $this->makeGallery();
        $conversation = $this->makeConversation();
        $tools = app(AgentTools::class);

        $pink = $tools->runTool('get_product', ['product_id' => $gallery['products']['pink']->id], $conversation);
        $brown = $tools->runTool('get_product', ['product_id' => $gallery['products']['brown']->id], $conversation);
        $cat = $tools->runTool('get_product', ['product_id' => $gallery['products']['cat']->id], $conversation);
        $unrelated = $tools->runTool('get_product', ['product_id' => $gallery['unrelated']->id], $conversation);

        $this->assertSame([$gallery['guide']->id], $pink['пояснення_розміру']);
        $this->assertSame([$gallery['guide']->id], $brown['пояснення_розміру']);
        $this->assertSame([$gallery['guide']->id], $cat['пояснення_розміру']);
        $this->assertNull($unrelated['пояснення_розміру']);
        $this->assertSame([$gallery['pinkPhoto']->id], $pink['фото']);
        $this->assertNull($brown['фото']);
        foreach ([$pink, $brown, $cat] as $product) {
            $this->assertSame($gallery['collage']->id, $product['колаж']);
            $this->assertSame([$gallery['collage']->id], $product['колажі_групи']);
            $this->assertNotContains($gallery['guide']->id, $product['фото'] ?? []);
        }
    }

    public function test_owner_data_exposes_purpose_without_counting_size_guides_as_product_photos(): void
    {
        $gallery = $this->makeGallery(false);
        $photo = AiPhoto::create([
            'ai_photo_group_id' => $gallery['group']->id, 'path' => 'ai-gallery/owner-teddy-pink.jpg',
            'sort_order' => 1,
        ]);
        $photo->products()->attach($gallery['products']['pink']->id);
        $owner = User::factory()->create(['role' => User::ROLE_OWNER, 'is_active' => true]);

        $data = $this->actingAs($owner)->getJson('/settings/ai-gallery/data')->assertOk()->json();
        $group = collect($data)->firstWhere('id', $gallery['group']->id);
        $photos = collect($group['photos'])->keyBy('id');
        $products = collect($group['products'])->keyBy('id');

        $this->assertSame(AiPhoto::PURPOSE_SIZE_GUIDE, $photos[$gallery['guide']->id]['purpose']);
        $this->assertSame(AiPhoto::PURPOSE_PRODUCT, $photos[$photo->id]['purpose']);
        $this->assertEqualsCanonicalizing(
            collect($gallery['products'])->pluck('id')->all(),
            $photos[$gallery['guide']->id]['product_ids']
        );
        $this->assertTrue($products[$gallery['products']['pink']->id]['has_photo']);
        $this->assertFalse($products[$gallery['products']['brown']->id]['has_photo']);
        $this->assertFalse($products[$gallery['products']['cat']->id]['has_photo']);
    }

    public function test_operator_cannot_read_or_change_size_guide_settings(): void
    {
        $guide = $this->makeGallery(false)['guide'];
        $operator = User::factory()->create(['role' => User::ROLE_OPERATOR, 'is_active' => true]);

        $this->actingAs($operator)->getJson('/settings/ai-gallery/data')->assertForbidden();
        $this->actingAs($operator)->patchJson("/settings/ai-gallery/photos/{$guide->id}", [
            'purpose' => AiPhoto::PURPOSE_PRODUCT,
        ])->assertForbidden();

        $this->assertSame(AiPhoto::PURPOSE_SIZE_GUIDE, $guide->fresh()->purpose);
    }
}
