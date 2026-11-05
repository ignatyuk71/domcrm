<?php

namespace Tests\Feature\Inbox;

use App\Models\AiPhotoGroup;
use App\Models\AiSetting;
use App\Models\Color;
use App\Models\Product;
use App\Services\Ai\Gpt\CommentCatalog;
use App\Services\Ai\Gpt\CommentReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GptCommentReplyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    public function test_catalog_keeps_only_active_products_and_available_active_sizes_without_internal_fields(): void
    {
        $color = Color::create(['name' => 'Чорний']);
        $item = $this->product('Капці', '399.99', ['color_id' => $color->id, 'stock_qty' => 50, 'cost_price' => '100.00']);
        $item->variants()->createMany([
            ['size' => '36/37', 'stock_qty' => 0, 'is_active' => true],
            ['size' => '38/39', 'stock_qty' => 2, 'is_active' => true],
            ['size' => '40/41', 'stock_qty' => 8, 'is_active' => false],
        ]);
        $unavailable = $this->product('Недоступні', '420.50', ['stock_qty' => 100]);
        $unavailable->variants()->create(['size' => '38/39', 'stock_qty' => 5, 'is_active' => false]);
        $this->product('Архівні', '100.00', ['is_active' => false]);
        $group = AiPhotoGroup::create(['name' => 'Домашні', 'ai_description' => 'М’яка модель.']);
        $group->products()->attach([$item->id, $unavailable->id]);

        $catalog = collect((new CommentCatalog)->snapshot())->keyBy('id');

        $this->assertCount(2, $catalog);
        $this->assertSame(['38/39'], $catalog[$item->id]['sizes']);
        $this->assertSame('399.99', $catalog[$item->id]['price']);
        $this->assertSame('Чорний', $catalog[$item->id]['color']);
        $this->assertTrue($catalog[$item->id]['available']);
        $this->assertFalse($catalog[$unavailable->id]['available']);
        $this->assertSame([['id' => $group->id, 'name' => 'Домашні', 'description' => 'М’яка модель.']], $catalog[$item->id]['groups']);
        $this->assertSame(['id', 'title', 'groups', 'color', 'sizes', 'price', 'available'], array_keys($catalog[$item->id]));
    }

    public function test_showcase_applies_membership_before_limit_and_keeps_zero_stock_products(): void
    {
        AiSetting::global()->update(['catalog_mode' => 'showcase']);
        $this->product('Поза вітриною', '1.00');
        $product = $this->product('З вітрини', '399.00', ['stock_qty' => 0]);
        $group = AiPhotoGroup::create(['name' => 'Вітрина']);
        $group->products()->attach($product->id);

        $catalog = (new CommentCatalog)->snapshot();

        $this->assertSame([$product->id], array_column($catalog, 'id'));
        $this->assertFalse($catalog[0]['available']);
    }

    public function test_snapshot_is_limited_and_does_not_query_relations_per_product(): void
    {
        Product::insert(array_map(fn ($index) => ['title' => 'Товар '.$index, 'sale_price' => '399.00', 'stock_qty' => 1,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now()], range(1, 305)));
        $group = AiPhotoGroup::create(['name' => 'Група']);
        $group->products()->attach(Product::limit(20)->pluck('id'));
        DB::enableQueryLog();

        $catalog = (new CommentCatalog)->snapshot();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(300, $catalog);
        $this->assertLessThanOrEqual(6, count($queries));
    }

    public function test_selected_fur_product_does_not_receive_cheaper_bear_price_from_same_group(): void
    {
        [$bear, $fur] = $this->items();

        $reply = (new CommentReply)->render($this->decision([$fur['id']]), [$bear, $fur], 'Яка ціна пухнастих?');

        $this->assertStringContainsString('399 грн', $reply['text']);
        $this->assertStringNotContainsString('380', $reply['text']);
        $this->assertStringContainsString('Пухнасті', $reply['text']);
        $this->assertSame('Домашні', $reply['matched_group_name']);
    }

    public function test_several_clear_models_get_an_exact_price_range_with_cents(): void
    {
        [$bear, $fur] = $this->items();
        $bear['price'] = '380.25';
        $fur['price'] = '399.99';

        $reply = (new CommentReply)->render($this->decision([1, 2]), [$bear, $fur], 'Які ціни?');

        $this->assertStringContainsString('Моделі з допису — 380,25–399,99 грн', $reply['text']);
    }

    public function test_uncertain_or_unknown_ids_do_not_publish_a_guessed_price(): void
    {
        $catalog = $this->items();
        foreach ([$this->decision([1, 2], ['confidence' => 'uncertain']), $this->decision([1, 999]), $this->decision(['1'])] as $decision) {
            $reply = (new CommentReply)->render($decision, $catalog, 'Ціна?');
            $this->assertStringNotContainsString('380', $reply['text']);
            $this->assertStringNotContainsString('399', $reply['text']);
            $this->assertStringContainsString('модель із допису', $reply['text']);
            $this->assertNull($reply['matched_group_name']);
        }
    }

    public function test_praise_is_skipped_and_complaint_has_no_sales_offer(): void
    {
        $renderer = new CommentReply;
        $praise = $renderer->render(['intent' => 'praise'], $this->items(), 'Красиві!');
        $complaint = $renderer->render($this->decision([1], ['intent' => 'complaint']), $this->items(), 'Потрібен обмін.');

        $this->assertTrue($praise['skip']);
        $this->assertSame('', $praise['text']);
        $this->assertTrue($complaint['handoff']);
        $this->assertFalse($complaint['skip']);
        $this->assertStringNotContainsString('грн', $complaint['text']);
        $this->assertStringNotContainsString('колір', $complaint['text']);
    }

    public function test_order_preserves_selection_and_does_not_ask_known_color_and_size_again(): void
    {
        $comment = 'Беру пухнасті чорні 38/39';
        $reply = (new CommentReply)->render($this->decision([2], ['intent' => 'order', 'has_color' => true, 'has_size' => true]), $this->items(), $comment);

        $this->assertStringContainsString($comment, $reply['text']);
        $this->assertStringContainsString('Пухнасті', $reply['text']);
        $this->assertStringContainsString('399 грн', $reply['text']);
        $this->assertStringContainsString('ПІБ', $reply['text']);
        $this->assertStringNotContainsString('який колір', $reply['text']);
        $this->assertStringNotContainsString('який розмір', $reply['text']);
        $unknown = (new CommentReply)->render($this->decision([], ['intent' => 'order', 'has_color' => true, 'has_size' => true]), [], $comment);
        $this->assertStringNotContainsString('ПІБ', $unknown['text']);
    }

    public function test_price_range_excludes_unavailable_cheaper_model_and_live_stock_is_rechecked(): void
    {
        [$bear, $fur] = $this->items();
        $bear['available'] = false;
        $reply = (new CommentReply)->render($this->decision([1, 2]), [$bear, $fur], 'Які ціни?');
        $this->assertStringContainsString('399 грн', $reply['text']);
        $this->assertStringNotContainsString('380', $reply['text']);
        $this->assertStringContainsString('немає в наявності', $reply['text']);

        $product = $this->product('Пухнасті', '399.00');
        $decision = $this->decision([$product->id]);
        $product->update(['sale_price' => '410.25']);
        $reply = (new CommentReply)->render($decision, (new CommentCatalog)->snapshot(), 'Ціна?');
        $this->assertStringContainsString('410,25 грн', $reply['text']);
        $this->assertStringNotContainsString('399', $reply['text']);
        $product->update(['stock_qty' => 0, 'sale_price' => '450.25']);
        $reply = (new CommentReply)->render($decision, (new CommentCatalog)->snapshot(), 'Ціна?');
        $this->assertStringContainsString('немає в наявності', $reply['text']);
        $this->assertStringNotContainsString('399', $reply['text']);
    }

    public function test_partly_unavailable_order_does_not_collect_delivery_until_selection_is_confirmed(): void
    {
        [$bear, $fur] = $this->items();
        $bear['available'] = false;
        $reply = (new CommentReply)->render($this->decision([1, 2], ['intent' => 'order', 'has_color' => true, 'has_size' => true]), [$bear, $fur], 'Беру обидві моделі чорні 38/39');

        $this->assertStringContainsString('підберемо заміну', $reply['text']);
        $this->assertStringNotContainsString('ПІБ', $reply['text']);
    }

    public function test_size_reply_uses_only_available_catalog_sizes_and_delivery_does_not_invent_terms(): void
    {
        $catalog = $this->items();
        $size = (new CommentReply)->render($this->decision([1], ['intent' => 'size', 'has_size' => true]), $catalog, 'Є 38/39?');
        $delivery = (new CommentReply)->render($this->decision([1], ['intent' => 'delivery']), $catalog, 'Яка доставка?');

        $this->assertStringContainsString('38/39, 40/41', $size['text']);
        $this->assertStringNotContainsString('36/37', $size['text']);
        $this->assertStringNotContainsString('який розмір', $size['text']);
        $this->assertStringContainsString('Новою Поштою', $delivery['text']);
        $this->assertStringNotContainsString('грн', $delivery['text']);
        $this->assertStringNotContainsString('днів', $delivery['text']);
    }

    public function test_invalid_price_is_not_rendered_as_free_or_rounded_to_another_value(): void
    {
        [$bear] = $this->items();
        foreach (['', '-1.00', '0.00', '399.999'] as $price) {
            $bear['price'] = $price;
            $reply = (new CommentReply)->render($this->decision([1]), [$bear], 'Ціна?');
            $this->assertTrue($reply['handoff']);
            $this->assertStringNotContainsString('грн', $reply['text']);
        }
    }

    private function items(): array
    {
        return [
            ['id' => 1, 'title' => 'Ведмедики', 'groups' => [['id' => 1, 'name' => 'Домашні', 'description' => '']],
                'color' => 'Чорний', 'sizes' => ['38/39', '40/41'], 'price' => '380.00', 'available' => true],
            ['id' => 2, 'title' => 'Пухнасті', 'groups' => [['id' => 1, 'name' => 'Домашні', 'description' => '']],
                'color' => 'Чорний', 'sizes' => ['38/39'], 'price' => '399.00', 'available' => true],
        ];
    }

    private function decision(array $ids, array $changes = []): array
    {
        return $changes + ['intent' => 'price', 'product_ids' => $ids, 'confidence' => 'clear', 'has_color' => false, 'has_size' => false];
    }

    private function product(string $title, string $price, array $changes = []): Product
    {
        return Product::create($changes + ['title' => $title, 'sale_price' => $price, 'stock_qty' => 2, 'is_active' => true]);
    }
}
