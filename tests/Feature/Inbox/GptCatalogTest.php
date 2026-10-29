<?php

namespace Tests\Feature\Inbox;

use App\Models\{AiPhoto, AiPhotoGroup, Product, User};
use App\Services\Ai\Gpt\{CatalogBuilder, CatalogReader};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class GptCatalogTest extends TestCase
{
    use RefreshDatabase;
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) if (is_file($file)) unlink($file);
        parent::tearDown();
    }

    public function test_live_gallery_keeps_product_collage_and_size_guide_links(): void
    {
        $products = collect(['Чорні', 'Сірі'])->map(fn ($title) => Product::create(['title' => $title, 'sale_price' => '399.00', 'is_active' => true]));
        $group = AiPhotoGroup::create(['name' => 'Домашні']); $group->products()->attach($products->pluck('id'));
        $photos = [];
        foreach (['product', 'collage', 'size_guide'] as $kind) {
            $path = 'ai-gallery/'.Str::uuid().'.png';
            if (! is_dir(public_path('ai-gallery'))) mkdir(public_path('ai-gallery'), 0775, true);
            $this->files[] = public_path($path);
            file_put_contents(public_path($path), UploadedFile::fake()->image('photo.png', 20, 20)->get());
            $photo = AiPhoto::create(['ai_photo_group_id' => $group->id, 'path' => $path, 'purpose' => $kind === 'size_guide' ? 'size_guide' : 'product']);
            $photo->products()->attach($kind === 'product' ? [$products[0]->id] : $products->pluck('id'));
            $photos[$kind] = $photo;
        }
        $reader = app(CatalogReader::class);
        $actual = collect($reader->photos(collect($photos)->pluck('id')->all()))->keyBy('id');
        foreach ($photos as $kind => $photo) $this->assertSame($kind, $actual[$photo->id]['kind']);
        $info = app(CatalogBuilder::class)->photoInfoFor($products->pluck('id')->all());
        $this->assertSame([$photos['product']->id], $info[$products[0]->id]['фото']);
        $this->assertSame($photos['collage']->id, $info[$products[0]->id]['колаж']);
        $this->assertSame([$photos['size_guide']->id], $info[$products[0]->id]['пояснення_розміру']);
        $group->products()->detach($products[0]->id);
        $this->assertSame([], $reader->photos([$photos['product']->id]));
        $photos['collage']->update(['path' => 'ai-gallery/../../.env']);
        $this->assertSame([], $reader->photos([$photos['collage']->id]));
    }

    public function test_retired_chat_endpoints_and_links_are_absent_without_api_requests(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $this->get('/settings/ai')->assertOk()->assertDontSee('Тестовий чат')->assertDontSee('gpt-sandbox');
        $this->get('/settings/gpt-sandbox')->assertNotFound();
        $this->getJson('/settings/gpt-sandbox/state')->assertNotFound();
        $this->postJson('/settings/gpt-sandbox/chats')->assertNotFound();
        Http::assertNothingSent();
    }
}
