<?php

namespace Tests\Feature\Products;

use App\Models\ExternalProduct;
use App\Models\Order;
use App\Models\OrderSource;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProductVariantPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    }

    public function test_edit_keeps_variant_id_and_existing_order_mapping_and_media_links(): void
    {
        [$product, $variant, $item, $mapping, $media] = $this->linkedVariant();

        $this->putJson('/products/'.$product->id, [
            'title' => 'Оновлена назва товару',
            'deleted_variant_ids' => [],
            'variants' => [$this->row($variant, [
                'size' => '39', 'sku' => 'UPDATED-39', 'stock_qty' => 8,
            ])],
        ])->assertOk();

        $this->assertDatabaseHas('product_variants', [
            'id' => $variant->id, 'product_id' => $product->id,
            'size' => '39', 'sku' => 'UPDATED-39', 'stock_qty' => 8,
        ]);
        $this->assertDatabaseCount('product_variants', 1);
        $this->assertSame('Оновлена назва товару', $product->fresh()->title);
        $this->assertSame(8, $product->fresh()->stock_qty);
        $this->assertSame($variant->id, $item->fresh()->product_variant_id);
        $this->assertSame($variant->id, $mapping->fresh()->product_variant_id);
        $this->assertSame($variant->id, $media->fresh()->variant_id);
        $match = app(\App\Services\Integration\ProductMatcher::class)->match($mapping->source, [
            'external_id' => $mapping->external_id, 'size' => $mapping->external_size,
        ]);
        $this->assertSame($variant->id, $match['product_variant_id']);
        $this->assertSame('memory', $match['via']);
        // Історичні характеристики позиції не змінюються разом із каталогом.
        $this->assertSame('38', $item->fresh()->size);
        $this->assertSame('MODEL-38', $item->fresh()->sku);
    }

    public function test_adds_new_variant_and_removes_only_the_explicitly_deleted_variant(): void
    {
        [$product, $kept, $item, $mapping, $media] = $this->linkedVariant();
        $removed = $product->variants()->create([
            'size' => '40', 'sku' => 'MODEL-40', 'stock_qty' => 10, 'is_active' => true,
        ]);

        $this->putJson('/products/'.$product->id, [
            'title' => $product->title,
            'deleted_variant_ids' => [$removed->id],
            'variants' => [
                $this->row($kept, ['stock_qty' => 3]),
                ['id' => null, 'size' => '42', 'sku' => 'MODEL-42', 'stock_qty' => 4, 'is_active' => true],
                ['id' => null, 'size' => '44', 'sku' => 'MODEL-44', 'stock_qty' => 100, 'is_active' => false],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('product_variants', ['id' => $kept->id, 'stock_qty' => 3]);
        $this->assertDatabaseMissing('product_variants', ['id' => $removed->id]);
        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => 'MODEL-42']);
        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => 'MODEL-44']);
        $this->assertDatabaseCount('product_variants', 3);
        $this->assertSame(7, $product->fresh()->stock_qty);
        $this->assertSame($kept->id, $item->fresh()->product_variant_id);
        $this->assertSame($kept->id, $mapping->fresh()->product_variant_id);
        $this->assertSame($kept->id, $media->fresh()->variant_id);
    }

    public function test_stale_form_does_not_delete_a_variant_added_by_another_manager(): void
    {
        [$product, $variant] = $this->linkedVariant();
        $addedElsewhere = $product->variants()->create([
            'size' => '42', 'sku' => 'ADDED-42', 'stock_qty' => 4, 'is_active' => true,
        ])->fresh();
        $before = $addedElsewhere->getAttributes();

        $this->putJson('/products/'.$product->id, [
            'title' => 'Збереження старої вкладки',
            'deleted_variant_ids' => [],
            'variants' => [$this->row($variant, ['stock_qty' => 3])],
        ])->assertOk();

        $this->assertSame($before, $addedElsewhere->fresh()?->getAttributes());
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock_qty' => 3]);
        $this->assertDatabaseCount('product_variants', 2);
        $this->assertSame(7, $product->fresh()->stock_qty);
    }

    public function test_new_idless_row_does_not_overwrite_an_unseen_variant_with_the_same_size(): void
    {
        [$product, $known] = $this->linkedVariant();
        $unseen = $product->variants()->create([
            'size' => '42', 'sku' => 'ADDED-ELSEWHERE', 'stock_qty' => 7, 'is_active' => true,
        ])->fresh();
        $before = $unseen->getAttributes();

        $this->putJson('/products/'.$product->id, [
            'title' => $product->title,
            'deleted_variant_ids' => [],
            'variants' => [
                $this->row($known),
                ['size' => '42', 'sku' => 'NEW-42', 'stock_qty' => 4, 'is_active' => true],
            ],
        ])->assertOk();

        $this->assertSame($before, $unseen->fresh()->getAttributes());
        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => 'NEW-42']);
        $this->assertDatabaseCount('product_variants', 3);
        $this->assertSame(17, $product->fresh()->stock_qty);
    }

    public static function absentVariants(): iterable
    {
        yield 'поле відсутнє' => [[]];
        yield 'явний null' => [['variants' => null]];
    }

    public function test_variant_stock_updates_keep_the_parent_total_in_sync(): void
    {
        [$product, $variant] = $this->linkedVariant();
        $product->variants()->create([
            'size' => '42', 'sku' => 'OTHER-42', 'stock_qty' => 4, 'is_active' => true,
        ]);

        $this->patchJson('/products/variants/'.$variant->id, [
            'stock_qty' => 9, 'is_active' => false,
        ])->assertOk();
        $this->assertSame(4, $product->fresh()->stock_qty);
        $this->assertFalse($variant->fresh()->is_active);

        $this->patchJson('/products/variants/'.$variant->id, [
            'stock_qty' => 9, 'is_active' => true,
        ])->assertOk();
        $this->assertSame(13, $product->fresh()->stock_qty);
    }

    public function test_inline_update_cannot_override_variant_stock_or_save_a_partial_price_change(): void
    {
        [$product] = $this->linkedVariant();
        $before = $product->getAttributes();

        $this->patchJson('/products/'.$product->id.'/inline', [
            'sale_price' => 500, 'stock_qty' => 999,
        ])->assertUnprocessable()->assertJsonValidationErrors('stock_qty');

        $this->assertSame($before, $product->fresh()->getAttributes());
    }

    #[DataProvider('absentVariants')]
    public function test_omitted_or_null_variants_do_not_change_existing_variants(array $extra): void
    {
        [$product, $variant, $item, $mapping, $media] = $this->linkedVariant();
        $before = $variant->getAttributes();

        $this->putJson('/products/'.$product->id, [
            'title' => 'Змінено лише товар', 'stock_qty' => 999, ...$extra,
        ])->assertOk();

        $this->assertSame('Змінено лише товар', $product->fresh()->title);
        $this->assertSame($before, $variant->fresh()?->getAttributes());
        $this->assertSame(6, $product->fresh()->stock_qty);
        $this->assertDatabaseCount('product_variants', 1);
        $this->assertSame($variant->id, $item->fresh()->product_variant_id);
        $this->assertSame($variant->id, $mapping->fresh()->product_variant_id);
        $this->assertSame($variant->id, $media->fresh()->variant_id);
    }

    public static function invalidIds(): iterable
    {
        yield 'ID іншого товару' => ['foreign'];
        yield 'неіснуючий ID' => ['missing'];
        yield 'повторний ID у запиті' => ['duplicate'];
    }

    #[DataProvider('invalidIds')]
    public function test_invalid_variant_ids_are_rejected_without_partial_changes(string $case): void
    {
        [$product, $variant, $item, $mapping, $media] = $this->linkedVariant();
        $other = Product::create(['title' => 'Інший товар', 'stock_qty' => 2]);
        $foreign = $other->variants()->create(['size' => '40', 'sku' => 'OTHER-40', 'stock_qty' => 2]);
        $productBefore = $product->getAttributes();
        $variantBefore = $variant->getAttributes();
        $foreignBefore = $foreign->fresh()->getAttributes();

        $rows = match ($case) {
            'foreign' => [$this->row($variant), $this->row($foreign)],
            'missing' => [$this->row($variant), ['id' => 999999, 'size' => '42', 'sku' => 'MISSING']],
            'duplicate' => [$this->row($variant), $this->row($variant, ['size' => '42'])],
        };

        $this->putJson('/products/'.$product->id, [
            'title' => 'Цю зміну слід відкотити', 'stock_qty' => 999,
            'deleted_variant_ids' => [], 'variants' => $rows,
        ])->assertUnprocessable()->assertJsonStructure(['errors']);

        $this->assertSame($productBefore, $product->fresh()->getAttributes());
        $this->assertSame($variantBefore, $variant->fresh()?->getAttributes());
        $this->assertSame($foreignBefore, $foreign->fresh()?->getAttributes());
        $this->assertDatabaseCount('product_variants', 2);
        $this->assertSame($variant->id, $item->fresh()->product_variant_id);
        $this->assertSame($variant->id, $mapping->fresh()->product_variant_id);
        $this->assertSame($variant->id, $media->fresh()->variant_id);
    }

    public static function invalidVariantPayloads(): iterable
    {
        yield 'пошкоджений JSON' => ['[{"size":'];
        yield 'JSON-об’єкт замість списку' => ['{}'];
        yield 'JSON-скаляр замість масиву' => ['"не масив"'];
        yield 'рядок замість рядка варіанта' => [['не масив']];
    }

    #[DataProvider('invalidVariantPayloads')]
    public function test_invalid_variant_payload_does_not_erase_the_existing_list(mixed $variants): void
    {
        [$product, $variant, $item, $mapping, $media] = $this->linkedVariant();
        $productBefore = $product->getAttributes();
        $variantBefore = $variant->getAttributes();

        $this->putJson('/products/'.$product->id, [
            'title' => 'Недопустима зміна', 'deleted_variant_ids' => [], 'variants' => $variants,
        ])->assertUnprocessable()->assertJsonStructure(['errors']);

        $this->assertSame($productBefore, $product->fresh()->getAttributes());
        $this->assertSame($variantBefore, $variant->fresh()?->getAttributes());
        $this->assertDatabaseCount('product_variants', 1);
        $this->assertSame($variant->id, $item->fresh()->product_variant_id);
        $this->assertSame($variant->id, $mapping->fresh()->product_variant_id);
        $this->assertSame($variant->id, $media->fresh()->variant_id);
    }

    public static function createVariantIds(): iterable
    {
        yield 'наявний ID' => [false];
        yield 'неіснуючий ID' => [true];
    }

    #[DataProvider('createVariantIds')]
    public function test_new_product_cannot_supply_a_variant_id(bool $missing): void
    {
        [$product, $variant] = $this->linkedVariant();

        $this->postJson('/products', [
            'title' => 'Неприпустимий новий товар',
            'variants' => [$this->row($variant, ['id' => $missing ? 999999 : $variant->id])],
        ])->assertUnprocessable()->assertJsonStructure(['errors']);

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 1);
        $this->assertSame($product->id, $variant->fresh()?->product_id);
    }

    public static function legacyMatches(): iterable
    {
        yield 'незмінний SKU, новий розмір' => [['size' => '39']];
        yield 'незмінний розмір, новий SKU' => [['sku' => 'RENAMED-38']];
    }

    #[DataProvider('legacyMatches')]
    public function test_legacy_idless_row_preserves_the_uniquely_matched_variant(array $changes): void
    {
        [$product, $variant, $item, $mapping, $media] = $this->linkedVariant();
        $row = $this->row($variant, ['stock_qty' => 9, ...$changes]);
        unset($row['id']);

        // Старі клієнти надсилають JSON-рядок без ID, як попередня форма товару.
        $this->putJson('/products/'.$product->id, [
            'title' => 'Legacy-клієнт', 'variants' => json_encode([$row], JSON_THROW_ON_ERROR),
        ])->assertOk();

        $this->assertDatabaseCount('product_variants', 1);
        $this->assertDatabaseHas('product_variants', [
            'id' => $variant->id, 'size' => $row['size'], 'sku' => $row['sku'], 'stock_qty' => 9,
        ]);
        $this->assertSame(9, $product->fresh()->stock_qty);
        $this->assertSame($variant->id, $item->fresh()->product_variant_id);
        $this->assertSame($variant->id, $mapping->fresh()->product_variant_id);
        $this->assertSame($variant->id, $media->fresh()->variant_id);
    }

    public function test_legacy_rename_without_a_stable_match_is_rejected_instead_of_replacing_the_variant(): void
    {
        [$product, $variant, $item, $mapping, $media] = $this->linkedVariant();
        $productBefore = $product->getAttributes();
        $variantBefore = $variant->getAttributes();

        $this->putJson('/products/'.$product->id, [
            'title' => 'Недопустима зміна',
            'variants' => [['size' => '42', 'sku' => 'RENAMED-42', 'stock_qty' => 9]],
        ])->assertUnprocessable()->assertJsonStructure(['errors']);

        $this->assertSame($productBefore, $product->fresh()->getAttributes());
        $this->assertSame($variantBefore, $variant->fresh()?->getAttributes());
        $this->assertSame($variant->id, $item->fresh()->product_variant_id);
        $this->assertSame($variant->id, $mapping->fresh()->product_variant_id);
        $this->assertSame($variant->id, $media->fresh()->variant_id);
    }

    public function test_explicit_deletion_removes_all_variants_and_resets_stock(): void
    {
        [$product, $variant, $item, $mapping, $media] = $this->linkedVariant();

        $this->putJson('/products/'.$product->id, [
            'title' => $product->title, 'variants' => [],
            'deleted_variant_ids' => json_encode([$variant->id], JSON_THROW_ON_ERROR),
        ])->assertOk();

        $this->assertDatabaseMissing('product_variants', ['id' => $variant->id]);
        $this->assertSame(0, $product->fresh()->stock_qty);
        $this->assertNull($item->fresh()->product_variant_id);
        $this->assertNull($mapping->fresh()->product_variant_id);
        $this->assertNull($media->fresh()->variant_id);
    }

    public function test_empty_legacy_list_cannot_silently_delete_existing_variants(): void
    {
        [$product, $variant, $item, $mapping, $media] = $this->linkedVariant();
        $before = $variant->getAttributes();

        $this->putJson('/products/'.$product->id, [
            'title' => $product->title, 'variants' => [],
        ])->assertUnprocessable()->assertJsonStructure(['errors']);

        $this->assertSame($before, $variant->fresh()?->getAttributes());
        $this->assertSame(6, $product->fresh()->stock_qty);
        $this->assertSame($variant->id, $item->fresh()->product_variant_id);
        $this->assertSame($variant->id, $mapping->fresh()->product_variant_id);
        $this->assertSame($variant->id, $media->fresh()->variant_id);
    }

    public function test_the_same_variant_cannot_be_updated_and_deleted_together(): void
    {
        [$product, $variant, $item, $mapping, $media] = $this->linkedVariant();
        $productBefore = $product->getAttributes();
        $variantBefore = $variant->getAttributes();

        $this->putJson('/products/'.$product->id, [
            'title' => 'Недопустима зміна',
            'variants' => [$this->row($variant, ['stock_qty' => 1])],
            'deleted_variant_ids' => [$variant->id],
        ])->assertUnprocessable()->assertJsonStructure(['errors']);

        $this->assertSame($productBefore, $product->fresh()->getAttributes());
        $this->assertSame($variantBefore, $variant->fresh()?->getAttributes());
        $this->assertSame($variant->id, $item->fresh()->product_variant_id);
        $this->assertSame($variant->id, $mapping->fresh()->product_variant_id);
        $this->assertSame($variant->id, $media->fresh()->variant_id);
    }

    public static function invalidDeletedIds(): iterable
    {
        yield 'ID іншого товару' => ['foreign'];
        yield 'неіснуючий ID' => ['missing'];
        yield 'повторний ID' => ['duplicate'];
        yield 'пошкоджений JSON' => ['json'];
        yield 'JSON-об’єкт замість списку' => ['object'];
    }

    #[DataProvider('invalidDeletedIds')]
    public function test_invalid_deletion_list_is_rejected_without_changing_any_variant(string $case): void
    {
        [$product, $variant, $item, $mapping, $media] = $this->linkedVariant();
        $other = Product::create(['title' => 'Інший товар']);
        $foreign = $other->variants()->create(['size' => '42', 'sku' => 'OTHER-42', 'stock_qty' => 2])->fresh();
        $productBefore = $product->getAttributes();
        $variantBefore = $variant->getAttributes();
        $foreignBefore = $foreign->getAttributes();
        $deletedIds = match ($case) {
            'foreign' => [$foreign->id],
            'missing' => [999999],
            'duplicate' => [$variant->id, $variant->id],
            'json' => '[',
            'object' => '{}',
        };

        $this->putJson('/products/'.$product->id, [
            'title' => 'Недопустима зміна', 'variants' => [], 'deleted_variant_ids' => $deletedIds,
        ])->assertUnprocessable()->assertJsonStructure(['errors']);

        $this->assertSame($productBefore, $product->fresh()->getAttributes());
        $this->assertSame($variantBefore, $variant->fresh()?->getAttributes());
        $this->assertSame($foreignBefore, $foreign->fresh()?->getAttributes());
        $this->assertDatabaseCount('product_variants', 2);
        $this->assertSame($variant->id, $item->fresh()->product_variant_id);
        $this->assertSame($variant->id, $mapping->fresh()->product_variant_id);
        $this->assertSame($variant->id, $media->fresh()->variant_id);
    }

    public static function ambiguousLegacyMatches(): iterable
    {
        yield 'неоднозначний SKU' => [
            ['size' => '40', 'sku' => 'MODEL-38'], ['size' => '42', 'sku' => 'MODEL-38'],
        ];
        yield 'неоднозначний розмір' => [
            ['size' => '38', 'sku' => 'ANOTHER-38'], ['size' => '38', 'sku' => 'RENAMED'],
        ];
    }

    #[DataProvider('ambiguousLegacyMatches')]
    public function test_ambiguous_legacy_match_is_rejected_without_replacing_variants(array $existing, array $incoming): void
    {
        [$product, $variant] = $this->linkedVariant();
        $second = $product->variants()->create([...$existing, 'stock_qty' => 2])->fresh();
        $productBefore = $product->getAttributes();
        $variantBefore = $variant->getAttributes();
        $secondBefore = $second->getAttributes();

        $this->putJson('/products/'.$product->id, [
            'title' => 'Недопустима зміна',
            'variants' => [[...$incoming, 'stock_qty' => 9]],
        ])->assertUnprocessable()->assertJsonStructure(['errors']);

        $this->assertSame($productBefore, $product->fresh()->getAttributes());
        $this->assertSame($variantBefore, $variant->fresh()?->getAttributes());
        $this->assertSame($secondBefore, $second->fresh()?->getAttributes());
        $this->assertDatabaseCount('product_variants', 2);
    }

    public function test_store_rolls_back_the_product_and_all_variants_when_a_variant_insert_fails(): void
    {
        $dispatcher = ProductVariant::getEventDispatcher();
        ProductVariant::setEventDispatcher(clone $dispatcher);

        try {
            $this->failCreatingVariant();
            $this->postJson('/products', [
                'title' => 'Товар із невдалим записом',
                'variants' => [
                    ['size' => '38', 'sku' => 'FIRST-38', 'stock_qty' => 2],
                    ['size' => '40', 'sku' => 'FAIL-40', 'stock_qty' => 4],
                ],
            ])->assertStatus(500);
        } finally {
            ProductVariant::setEventDispatcher($dispatcher);
        }

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_variants', 0);
    }

    public function test_update_rolls_back_product_variant_edits_and_links_when_a_new_variant_insert_fails(): void
    {
        [$product, $variant, $item, $mapping, $media] = $this->linkedVariant();
        $productBefore = $product->getAttributes();
        $variantBefore = $variant->getAttributes();
        $dispatcher = ProductVariant::getEventDispatcher();
        ProductVariant::setEventDispatcher(clone $dispatcher);

        try {
            $this->failCreatingVariant();
            $this->putJson('/products/'.$product->id, [
                'title' => 'Назва з невдалого запиту',
                'deleted_variant_ids' => [],
                'variants' => [
                    $this->row($variant, ['stock_qty' => 1, 'size' => '39']),
                    ['size' => '42', 'sku' => 'FIRST-42', 'stock_qty' => 2],
                    ['size' => '40', 'sku' => 'FAIL-40', 'stock_qty' => 4],
                ],
            ])->assertStatus(500);
        } finally {
            ProductVariant::setEventDispatcher($dispatcher);
        }

        $this->assertSame($productBefore, $product->fresh()->getAttributes());
        $this->assertSame($variantBefore, $variant->fresh()?->getAttributes());
        $this->assertDatabaseCount('product_variants', 1);
        $this->assertSame($variant->id, $item->fresh()->product_variant_id);
        $this->assertSame($variant->id, $mapping->fresh()->product_variant_id);
        $this->assertSame($variant->id, $media->fresh()->variant_id);
    }

    private function linkedVariant(): array
    {
        $product = Product::create(['title' => 'Товар', 'sku' => 'MODEL', 'stock_qty' => 6]);
        $variant = $product->variants()->create([
            'size' => '38', 'sku' => 'MODEL-38', 'stock_qty' => 6, 'is_active' => true,
        ]);
        $order = Order::create(['order_number' => 'VARIANT-PERSISTENCE', 'currency' => 'UAH']);
        $item = $order->items()->create([
            'product_id' => $product->id, 'product_variant_id' => $variant->id,
            'product_title' => $product->title, 'sku' => $variant->sku, 'size' => $variant->size,
            'price' => 399, 'qty' => 1, 'total' => 399,
        ]);
        $source = OrderSource::create(['code' => 'variant-test', 'name' => 'Тестове джерело']);
        $mapping = ExternalProduct::create([
            'source_id' => $source->id, 'external_id' => 'WEB-38', 'external_size' => '38',
            'product_id' => $product->id, 'product_variant_id' => $variant->id,
        ]);
        $media = ProductMedia::create([
            'product_id' => $product->id, 'variant_id' => $variant->id,
            'media_type' => 'image', 'url' => '/test-variant.jpg',
        ]);

        return [$product->fresh(), $variant->fresh(), $item, $mapping, $media];
    }

    private function row(ProductVariant $variant, array $changes = []): array
    {
        return [
            'id' => $variant->id,
            'size' => $variant->size,
            'sku' => $variant->sku,
            'stock_qty' => $variant->stock_qty,
            'is_active' => $variant->is_active,
            ...$changes,
        ];
    }

    private function failCreatingVariant(): void
    {
        ProductVariant::creating(function (ProductVariant $variant): void {
            if ($variant->sku === 'FAIL-40') {
                throw new RuntimeException('Тестова помилка запису варіанта.');
            }
        });
    }
}
