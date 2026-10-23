<?php

namespace Tests\Feature\Inbox;

use App\Models\AiPhoto;
use App\Models\AiPhotoGroup;
use App\Models\InboxContact;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\MetaConnection;
use App\Models\Product;
use App\Services\Ai\CatalogBuilder;
use App\Services\Ai\HistoryBuilder;
use App\Services\Ai\ImageMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiSizeGuideCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function gallery(): array
    {
        $products = collect([
            'Котики — рожеві', 'Котики — сірі', 'Ведмедики — коричневі',
            'Ведмедики — рожеві', 'Сердечка — білі',
        ])->map(fn ($title, $index) => Product::create([
            'title' => $title, 'sku' => 'SIZE-GUIDE-'.$index,
            'sale_price' => 380, 'currency' => 'UAH', 'is_active' => true,
        ]));
        $group = AiPhotoGroup::create(['name' => 'Жіночі теплі домашні капці']);
        $group->products()->attach($products->pluck('id')->all());
        $collage = AiPhoto::create([
            'ai_photo_group_id' => $group->id, 'path' => 'ai-gallery/all-slippers.jpg', 'sort_order' => 0,
        ]);
        $collage->products()->attach($products->pluck('id')->all());
        $photos = $products->map(function ($product, $index) use ($group) {
            $photo = AiPhoto::create([
                'ai_photo_group_id' => $group->id, 'path' => "ai-gallery/slipper-{$index}.jpg", 'sort_order' => $index + 1,
            ]);
            $photo->products()->attach($product->id);

            return $photo;
        });
        $guide = AiPhoto::create([
            'ai_photo_group_id' => $group->id, 'path' => 'ai-gallery/warm-slippers-size-guide.jpg',
            'sort_order' => 6, 'purpose' => AiPhoto::PURPOSE_SIZE_GUIDE,
        ]);
        $guide->products()->attach($products->pluck('id')->all());

        return [$group, $products, $collage, $photos, $guide];
    }

    private function conversation(): InboxConversation
    {
        $connection = MetaConnection::create([
            'page_id' => 'SIZE_GUIDE', 'page_name' => 'Shop',
            'page_access_token' => 'test-token', 'status' => 'active',
        ]);
        $contact = InboxContact::create([
            'meta_connection_id' => $connection->id, 'channel' => 'facebook', 'external_id' => 'SIZE_GUIDE_CLIENT',
        ]);

        return InboxConversation::create([
            'meta_connection_id' => $connection->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook',
        ]);
    }

    private function message(InboxConversation $conversation, array $attributes): InboxMessage
    {
        return InboxMessage::create(array_merge([
            'inbox_conversation_id' => $conversation->id, 'direction' => 'in',
            'sender' => 'contact', 'sent_at' => now(),
        ], $attributes));
    }

    public function test_confirmed_size_guide_is_available_for_all_five_group_products_and_not_unrelated_items(): void
    {
        [$group, $products, $collage, $photos, $guide] = $this->gallery();
        $catalogBuilder = app(CatalogBuilder::class);
        $info = $catalogBuilder->photoInfoFor($products->pluck('id')->all());

        foreach ($products as $index => $product) {
            $this->assertSame([$photos[$index]->id], $info[$product->id]['фото']);
            $this->assertSame($collage->id, $info[$product->id]['колаж']);
            $this->assertSame([$collage->id], $info[$product->id]['колажі_групи']);
            $expectedGuides = [$guide->id];
            $this->assertSame($expectedGuides, $info[$product->id]['пояснення_розміру']);
            $this->assertSame($expectedGuides, $catalogBuilder->productSummary($product, $info)['пояснення_розміру']);
        }

        $catalog = $catalogBuilder->buildCatalog();
        $this->assertStringContainsString("Колажі лінії: [{$collage->id}]", $catalog);
        $this->assertStringNotContainsString("Колажі лінії: [{$collage->id},{$guide->id}]", $catalog);
        $this->assertSame(5, substr_count($catalog, "пояснення_розміру:[{$guide->id}]"));
        foreach ($products as $index => $product) {
            $line = collect(explode("\n", $catalog))->first(fn ($line) => str_contains($line, '#'.$product->id.' |'));
            $this->assertNotNull($line);
            $this->assertStringContainsString("фото:[{$photos[$index]->id}]", $line);
            $this->assertStringContainsString("пояснення_розміру:[{$guide->id}]", $line);
        }

        $unrelated = Product::create([
            'title' => 'Інші вуличні капці', 'sku' => 'UNRELATED-SIZE-GUIDE',
            'sale_price' => 500, 'currency' => 'UAH', 'is_active' => true,
        ]);
        $unrelatedInfo = $catalogBuilder->photoInfoFor([$unrelated->id]);
        $this->assertArrayNotHasKey($unrelated->id, $unrelatedInfo);
        $this->assertNull($catalogBuilder->productSummary($unrelated, $unrelatedInfo)['пояснення_розміру']);
        $unrelatedLine = collect(explode("\n", $catalogBuilder->buildCatalog()))
            ->first(fn ($line) => str_contains($line, '#'.$unrelated->id.' |'));
        $this->assertNotNull($unrelatedLine);
        $this->assertStringNotContainsString('пояснення_розміру:', $unrelatedLine);

        // Окрема прив’язка одного товару теж не стає його фото кольору.
        // Поширення пояснення завжди визначають відмітки, а не назва чи колір моделі.
        $guide->products()->sync([$products[2]->id]);
        $singleInfo = $catalogBuilder->photoInfoFor($products->pluck('id')->all());
        $this->assertSame([$photos[2]->id], $singleInfo[$products[2]->id]['фото']);
        $this->assertSame([$guide->id], $singleInfo[$products[2]->id]['пояснення_розміру']);
        foreach ([0, 1, 3, 4] as $index) {
            $this->assertNull($singleInfo[$products[$index]->id]['пояснення_розміру']);
        }
        $this->assertFalse($collage->fresh()->isSizeGuide());
        $this->assertTrue($guide->fresh()->isSizeGuide());
    }

    public function test_guide_without_product_marks_is_not_exposed_and_cannot_become_group_collage(): void
    {
        [$group, $products, $collage, , $guide] = $this->gallery();
        $collage->delete();
        $catalogBuilder = app(CatalogBuilder::class);
        $linkedInfo = $catalogBuilder->photoInfoFor($products->pluck('id')->all());
        $this->assertNull($linkedInfo[$products[2]->id]['колаж']);
        $this->assertNull($linkedInfo[$products[2]->id]['колажі_групи']);
        $this->assertSame([$guide->id], $linkedInfo[$products[2]->id]['пояснення_розміру']);
        $this->assertStringNotContainsString('Колажі лінії:', $catalogBuilder->buildCatalog());
        $guide->products()->detach();
        $info = $catalogBuilder->photoInfoFor($products->pluck('id')->all());

        foreach ($products as $product) {
            $this->assertNull($info[$product->id]['колаж']);
            $this->assertNull($info[$product->id]['колажі_групи']);
            $this->assertNull($info[$product->id]['пояснення_розміру']);
        }
        $this->assertStringNotContainsString('Колажі лінії:', $catalogBuilder->buildCatalog());
        $this->assertStringNotContainsString('пояснення_розміру:', $catalogBuilder->buildCatalog());
    }

    public function test_legacy_photo_creation_defaults_to_product_in_model_and_database(): void
    {
        $group = AiPhotoGroup::create(['name' => 'Попередня галерея']);
        $photo = AiPhoto::create(['ai_photo_group_id' => $group->id, 'path' => 'ai-gallery/legacy.jpg']);
        $this->assertSame(AiPhoto::PURPOSE_PRODUCT, $photo->purpose);
        $this->assertSame(AiPhoto::PURPOSE_PRODUCT, $photo->fresh()->purpose);
        $this->assertFalse($photo->isSizeGuide());

        // Імпорт без Eloquent теж отримує безпечне значення за замовчуванням.
        $id = DB::table('ai_photos')->insertGetId([
            'ai_photo_group_id' => $group->id, 'path' => 'ai-gallery/legacy-import.jpg', 'sort_order' => 0,
        ]);
        $this->assertSame(AiPhoto::PURPOSE_PRODUCT, AiPhoto::findOrFail($id)->purpose);
    }

    public function test_sent_size_guide_and_reply_preserve_measurement_context_without_color_selection(): void
    {
        [, $products, , , $guide] = $this->gallery();
        $conversation = $this->conversation();
        $this->message($conversation, ['text' => 'Чому маломірять?', 'external_message_id' => 'guide-question']);
        $this->message($conversation, [
            'direction' => 'out', 'sender' => 'ai', 'external_message_id' => 'guide-sent',
            'attachments' => [['type' => 'image', 'url' => url($guide->path)]],
        ]);
        $this->message($conversation, [
            'text' => 'А для іншого розміру?', 'external_message_id' => 'guide-reply',
            'context' => ['type' => 'reply', 'mid' => 'guide-sent'],
        ]);

        $history = json_encode(app(HistoryBuilder::class)->buildHistory($conversation), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString("пояснення розміру — фото №{$guide->id}", $history);
        $this->assertStringContainsString('приклад замірів і заднього бортика', $history);
        $this->assertStringContainsString('для інших розмірів потрібні їхні фактичні заміри', $history);
        $this->assertStringContainsString('НЕ означає, що клієнт обрав зображену модель, колір або розмір', $history);
        $this->assertStringContainsString('у відповідь на наше пояснення розміру', $history);
        $this->assertStringContainsString('#'.$products[2]->id.' '.$products[2]->title, $history);
        $this->assertStringContainsString('#'.$products[3]->id.' '.$products[3]->title, $history);
        foreach ($products as $product) {
            $this->assertStringContainsString('#'.$product->id.' '.$product->title, $history);
        }
        $this->assertStringNotContainsString('380 грн', $history);
        $this->assertStringNotContainsString('ти надіслала клієнту фото:', $history);
        $this->assertStringNotContainsString('на ньому товари:', $history);
    }

    public function test_guide_note_is_preserved_when_photo_has_text_and_is_quoted_outside_history_window(): void
    {
        [, , , , $guide] = $this->gallery();
        $conversation = $this->conversation();
        $this->message($conversation, ['text' => 'Яка довжина?', 'external_message_id' => 'guide-text-question']);
        $this->message($conversation, [
            'direction' => 'out', 'sender' => 'ai', 'external_message_id' => 'guide-with-text',
            'text' => 'Ось приклад для 36/37.',
            'attachments' => [['type' => 'image', 'url' => url($guide->path)]],
        ]);
        $this->message($conversation, [
            'text' => 'Зрозуміло, а 38/39?', 'external_message_id' => 'guide-text-reply',
            'context' => ['type' => 'reply', 'mid' => 'guide-with-text'],
        ]);

        $fullHistory = app(HistoryBuilder::class)->buildHistory($conversation);
        $sent = json_encode($fullHistory[1], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('пояснення розміру', $sent);
        $this->assertStringContainsString('Ось приклад для 36/37.', $sent);

        // Навіть коли саме фото вже поза вікном історії, reply зберігає його призначення.
        $history = json_encode(app(HistoryBuilder::class)->buildHistory($conversation, 1), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('у відповідь на наше пояснення розміру', $history);
        $this->assertStringContainsString('Ось приклад для 36/37.', $history);
        $this->assertStringContainsString('НЕ означає, що клієнт обрав', $history);
    }

    public function test_ordinary_collage_history_keeps_product_names_and_prices(): void
    {
        [, $products, $collage] = $this->gallery();
        $conversation = $this->conversation();
        $this->message($conversation, ['text' => 'Покажіть капці', 'external_message_id' => 'ordinary-question']);
        $this->message($conversation, [
            'direction' => 'out', 'sender' => 'ai', 'external_message_id' => 'ordinary-collage',
            'attachments' => [['type' => 'image', 'url' => url($collage->path)]],
        ]);
        $history = json_encode(app(HistoryBuilder::class)->buildHistory($conversation), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('ти надіслала клієнту фото:', $history);
        $this->assertStringContainsString($products[0]->title.' — 380 грн', $history);
        $this->assertStringNotContainsString('пояснення розміру', $history);
    }

    public function test_incoming_size_illustration_does_not_force_collage_or_color_selection(): void
    {
        [, , , , $guide] = $this->gallery();
        $conversation = $this->conversation();
        $this->message($conversation, [
            'text' => 'Чому тут 23,3, а не 24?', 'external_message_id' => 'incoming-guide',
            'attachments' => [['type' => 'image', 'url' => 'https://cdn.meta/size-illustration.jpg']],
        ]);
        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==');
        Http::fake(['cdn.meta/*' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg'])]);

        $history = json_encode(app(HistoryBuilder::class)->buildHistory($conversation), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('КОЛІР із фото НЕ визначай', $history);
        $this->assertStringContainsString('Якщо це ілюстрація замірів або заднього бортика — поясни її як приклад одного розміру', $history);
        $this->assertStringContainsString('колаж і вибір кольору через таку ілюстрацію НЕ нав’язуй', $history);
        $this->assertStringContainsString('Наступні дії доречні лише для товарного фото', $history);
    }

    public function test_image_matching_excludes_guides_even_with_a_perfect_hash_match(): void
    {
        [$group, , , , $guide] = $this->gallery();
        $guide->update(['phash' => str_repeat('0', 16)]);
        $image = imagecreatetruecolor(16, 16);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        $this->assertNull(app(ImageMatcher::class)->matchGalleryPhoto($bytes));
        $photo = AiPhoto::create([
            'ai_photo_group_id' => $group->id, 'path' => 'ai-gallery/matched-product.jpg', 'phash' => str_repeat('0', 16),
        ]);
        $this->assertSame($photo->id, app(ImageMatcher::class)->matchGalleryPhoto($bytes)?->id);
    }
}
