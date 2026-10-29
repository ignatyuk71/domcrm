<?php

namespace App\Modules\GptSandbox\Infrastructure;

use App\Models\AiPhoto;
use App\Models\AiPhotoGroup;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** Читання лише публічних товарних фактів; жодних змін каталогу чи клієнтських даних. */
class CatalogReader
{
    public function __construct(private bool $allowSnapshot = false) {}

    private const PAGE_SIZE = 20;

    private const ASSORTMENT_LIMIT = 200;

    private ?array $snapshotMetadata = null;

    private ?bool $hasPhotoPurpose = null;

    public function overview(): array
    {
        $result = [
            'products' => Product::where('is_active', true)->count(),
            'photos' => $this->photoQuery()->count(),
            'categories' => Category::query()->whereHas('products', fn ($q) => $q->where('is_active', true))
                ->withCount(['products as product_count' => fn ($q) => $q->where('is_active', true)])
                ->orderBy('sort_order')->orderBy('id')->get(['id', 'name'])
                ->map(fn ($category) => ['id' => $category->id, 'name' => $category->name, 'products' => $category->product_count])->all(),
            'groups' => AiPhotoGroup::query()->whereHas('products', fn ($q) => $q->where('is_active', true))
                ->orderBy('sort_order')->orderBy('id')->get(['id', 'name'])
                ->map(fn ($group) => ['id' => $group->id, 'name' => $group->name])->all(),
            'source' => 'database',
            'captured_at' => null,
        ];

        if ($meta = $this->snapshot()) {
            $result['source'] = 'server_snapshot';
            $result['captured_at'] = $meta['captured_at'] ?? null;
        }

        return $result;
    }

    /** Змістовний контекст для GPT без цін, залишків, службових полів і класифікації запиту кодом. */
    public function assortment(): array
    {
        $rows = Product::query()->where('is_active', true)->with('color:id,name')
            ->orderBy('id')->limit(self::ASSORTMENT_LIMIT + 1)->get(['id', 'title', 'category_id', 'color_id', 'description']);
        $hasMore = $rows->count() > self::ASSORTMENT_LIMIT;
        $rows = $rows->take(self::ASSORTMENT_LIMIT);
        $groups = AiPhotoGroup::query()->whereHas('products', fn ($q) => $q->where('is_active', true))
            ->with(['products' => fn ($q) => $q->where('is_active', true)->whereIn('products.id', $rows->pluck('id'))->select('products.id')])
            ->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'ai_description']);

        return [
            'store_context' => trim(file_get_contents(__DIR__.'/../Context/assortment.md')),
            'groups' => $groups->map(fn ($group) => [
                'id' => $group->id, 'name' => $group->name, 'description' => $this->plain($group->ai_description),
                'product_ids' => $group->products->pluck('id')->all(),
            ])->all(),
            'products' => $rows->map(fn ($product) => [
                'id' => $product->id, 'title' => $product->title, 'category_id' => $product->category_id,
                'color' => $product->color?->name,
                'description_excerpt' => mb_substr($this->plain($product->description) ?? '', 0, 360),
            ])->values()->all(),
            'has_more' => $hasMore,
            'next_after_id' => $hasMore ? $rows->last()->id : null,
        ];
    }

    private function snapshot(): array
    {
        if ($this->snapshotMetadata !== null) {
            return $this->snapshotMetadata;
        }
        $this->snapshotMetadata = [];
        if ($this->allowSnapshot && DB::connection()->getDriverName() === 'sqlite' && Storage::disk('local')->exists('gpt-sandbox/catalog-source.json')) {
            $meta = json_decode(Storage::disk('local')->get('gpt-sandbox/catalog-source.json'), true);
            if (is_array($meta) && ($meta['database'] ?? null) === DB::connection()->getDatabaseName()) {
                $this->snapshotMetadata = $meta;
            }
        }

        return $this->snapshotMetadata;
    }

    public function browse(array $filters): array
    {
        $query = $this->products();
        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }
        if (! empty($filters['group_id'])) {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('ai_photo_group_product as membership')
                ->whereColumn('membership.product_id', 'products.id')->where('membership.ai_photo_group_id', $filters['group_id']));
        }
        if (! empty($filters['color'])) {
            $query->whereHas('color', fn ($q) => $this->contains($q, 'name', $filters['color']));
        }
        if (! empty($filters['size'])) {
            $query->whereHas('variants', function ($q) use ($filters) {
                $q->where('is_active', true)->when($filters['in_stock_only'] ?? false, fn ($q) => $q->where('stock_qty', '>', 0));
                $this->matchingSize($q, $filters['size']);
            });
        }
        if (($filters['max_price'] ?? null) !== null) {
            $query->where('sale_price', '<=', $filters['max_price']);
        }
        if (! empty($filters['in_stock_only'])) {
            $query->where(fn ($q) => $q->whereHas('variants', fn ($q) => $q->where('is_active', true)->where('stock_qty', '>', 0))
                ->orWhere(fn ($q) => $q->whereDoesntHave('variants')->where('stock_qty', '>', 0)));
        }
        foreach (array_slice(preg_split('/\s+/u', trim($filters['query'] ?? ''), -1, PREG_SPLIT_NO_EMPTY), 0, 6) as $word) {
            $query->where(function ($q) use ($word) {
                $this->contains($q, 'title', $word);
                $q->orWhere(fn ($q) => $this->contains($q, 'sku', $word))
                    ->orWhere(fn ($q) => $this->contains($q, 'description', $word))
                    ->orWhereHas('category', fn ($q) => $this->contains($q, 'name', $word))
                    ->orWhereHas('color', fn ($q) => $this->contains($q, 'name', $word));
            });
        }
        $rows = $query->when($filters['after_id'] ?? null, fn ($q, $id) => $q->where('products.id', '>', $id))
            ->orderBy('products.id')->limit(self::PAGE_SIZE + 1)->get();
        $hasMore = $rows->count() > self::PAGE_SIZE;
        $rows = $rows->take(self::PAGE_SIZE);
        $photos = $this->photosFor($rows->pluck('id')->all());
        $groups = AiPhotoGroup::whereHas('products', fn ($q) => $q->whereIn('products.id', $rows->pluck('id')))
            ->with('products:products.id')->get(['id', 'name', 'ai_description']);

        return [
            'products' => $rows->map(fn ($product) => $this->card($product, $photos, $groups, false))->values()->all(),
            'groups' => $groups->map(fn ($group) => ['id' => $group->id, 'name' => $group->name, 'description' => $this->plain($group->ai_description)])->values()->all(),
            'has_more' => $hasMore,
            'next_after_id' => $hasMore ? $rows->last()->id : null,
        ];
    }

    public function product(int $id): array
    {
        $product = $this->products()->find($id);
        if (! $product) {
            return ['error' => 'Активний товар із таким ID не знайдений.'];
        }
        $groups = AiPhotoGroup::whereHas('products', fn ($q) => $q->where('products.id', $id))
            ->with('products:products.id')->get(['id', 'name', 'ai_description']);

        return $this->card($product, $this->photosFor([$id]), $groups);
    }

    private function products(): Builder
    {
        return Product::query()->select(['id', 'title', 'sku', 'category_id', 'color_id', 'sale_price', 'currency', 'stock_qty', 'description'])
            ->where('is_active', true)->with(['category:id,name', 'color:id,name', 'variants:id,product_id,size,stock_qty,is_active']);
    }

    private function card(Product $product, array $photos, $groups, bool $full = true): array
    {
        $variants = $product->variants->where('is_active', true)->values();
        $stock = $product->variants->isNotEmpty() ? (int) $variants->sum('stock_qty') : $product->stock_qty;

        return [
            'id' => $product->id, 'title' => $product->title, 'sku' => $product->sku,
            'category' => $product->category?->name, 'color' => $product->color?->name,
            'price' => $product->sale_price, 'currency' => $product->currency,
            'stock_qty' => $stock, 'in_stock' => $stock === null ? null : $stock > 0,
            'sizes' => $variants->map(fn ($variant) => ['size' => $variant->size, 'stock_qty' => $variant->stock_qty])->all(),
            'description' => $full ? $this->plain($product->description) : mb_substr($this->plain($product->description) ?? '', 0, 1200),
            'groups' => $groups->filter(fn ($group) => $group->products->contains('id', $product->id))
                ->map(fn ($group) => array_merge(['id' => $group->id, 'name' => $group->name], $full ? ['description' => $this->plain($group->ai_description)] : []))->values()->all(),
            'photos' => array_values(array_filter($photos, fn ($photo) => in_array($product->id, $photo['product_ids'], true))),
        ];
    }

    private function plain(?string $text): ?string
    {
        return $text === null ? null : mb_substr(trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 6000);
    }

    private function contains(Builder $query, string $column, string $needle): void
    {
        $function = 'LOWER';
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateFunction('gpt_sandbox_lower', fn ($value) => mb_strtolower((string) $value), 1);
            $function = 'gpt_sandbox_lower';
        }
        $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($needle)));
        $query->whereRaw("{$function}({$column}) LIKE ? ESCAPE '!'", ['%'.$literal.'%']);
    }

    private function matchingSize(Builder $query, string $size): void
    {
        $size = trim($size);
        $query->where(function ($q) use ($size) {
            $q->where('size', $size);
            // У каталозі є підписи «38/39 - 25см»; «38» не має збігатися з «38/39».
            if (preg_match('/^\d{1,3}(?:\/\d{1,3})?$/', $size)) {
                foreach (['-', '–', '—'] as $separator) {
                    $q->orWhereRaw("REPLACE(size, ' ', '') LIKE ?", [$size.$separator.'%']);
                }
            }
        });
    }

    private function photoQuery(): Builder
    {
        return AiPhoto::query()->whereExists(fn ($q) => $q->selectRaw('1')->from('ai_photo_product as tag')
            ->join('products as tagged_product', 'tagged_product.id', '=', 'tag.product_id')
            ->join('ai_photo_group_product as membership', function ($join) {
                $join->on('membership.product_id', '=', 'tag.product_id')->on('membership.ai_photo_group_id', '=', 'ai_photos.ai_photo_group_id');
            })->whereColumn('tag.ai_photo_id', 'ai_photos.id')->where('tagged_product.is_active', true));
    }

    public function photos(array $ids): array
    {
        return $this->photoRows($this->photoQuery()->whereIn('ai_photos.id', $ids));
    }

    private function photosFor(array $productIds): array
    {
        if (! $productIds) {
            return [];
        }

        return $this->photoRows($this->photoQuery()->whereHas('products', fn ($q) => $q->whereIn('products.id', $productIds)));
    }

    private function photoRows(Builder $query): array
    {
        $columns = ['ai_photos.id', 'ai_photo_group_id', 'path', 'sort_order'];
        $this->hasPhotoPurpose ??= Schema::hasColumn('ai_photos', 'purpose');
        if ($this->hasPhotoPurpose) {
            $columns[] = 'purpose';
        }

        return $query->with(['products:products.id,title,is_active', 'group:id,name', 'group.products:products.id'])
            ->orderBy('sort_order')->orderBy('ai_photos.id')->get($columns)
            ->filter(fn ($photo) => $this->fileFor($photo) !== null)
            ->map(function ($photo) {
                $products = $photo->products->filter(fn ($product) => $product->is_active && $photo->group->products->contains('id', $product->id));
                $purpose = $this->photoPurpose($photo);
                // Призначення має пріоритет: спільне фото заміру не є колажем асортименту.
                $kind = $purpose === 'size_guide' ? 'size_guide' : ($photo->products->count() > 1 ? 'collage' : 'product');

                return [
                    'id' => $photo->id,
                    'purpose' => $purpose,
                    'kind' => $kind,
                    'group_id' => $photo->ai_photo_group_id,
                    'product_ids' => $products->pluck('id')->values()->all(),
                    'label' => match ($kind) {
                        'size_guide' => 'Пояснення розміру · '.$photo->group->name,
                        'collage' => $photo->group->name,
                        default => $products->pluck('title')->implode(' · '),
                    },
                ];
            })->values()->all();
    }

    private function photoPurpose(AiPhoto $photo): string
    {
        if (array_key_exists('purpose', $photo->getAttributes())) {
            return $photo->getAttribute('purpose') === 'size_guide' ? 'size_guide' : 'product';
        }
        // Локальна копія може мати стару схему; позначки зберігаємо лише в GPT-модулі.
        $entry = $this->snapshot()['photo_purposes'][$photo->id] ?? null;

        return is_array($entry) && ($entry['path'] ?? null) === $photo->path && ($entry['purpose'] ?? null) === 'size_guide'
            ? 'size_guide' : 'product';
    }

    public function image(int $id): ?array
    {
        $photo = $this->photoQuery()->find($id);

        return $photo ? $this->fileFor($photo) : null;
    }

    private function fileFor(AiPhoto $photo): ?array
    {
        $relative = ltrim($photo->path, '/');
        if (! str_starts_with($relative, 'ai-gallery/') || str_contains($relative, '..') || str_contains($relative, '\\')) {
            return null;
        }
        $roots = [public_path('ai-gallery')];
        if ($this->snapshot()) {
            array_unshift($roots, Storage::disk('local')->path('gpt-sandbox/catalog-media/ai-gallery'));
        }
        foreach ($roots as $root) {
            $base = realpath($root);
            $file = realpath($root.'/'.substr($relative, strlen('ai-gallery/')));
            if (! $base || ! $file || ! str_starts_with($file, $base.DIRECTORY_SEPARATOR) || ! is_file($file)) {
                continue;
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file);
            if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
                return ['path' => $file, 'mime' => $mime];
            }
        }

        return null;
    }
}
