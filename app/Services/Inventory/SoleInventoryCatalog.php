<?php

namespace App\Services\Inventory;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SoleInventoryCatalog
{
    public const CATEGORIES = [
        'Домашні капці Halluci (хутряні)' => ['36/37', '38/39', '40/41'],
        'Капці для вулиці (хутряні)' => ['36/37', '38/39', '40/41', '42/43'],
    ];

    public function categories(): Collection
    {
        return DB::table('categories')->whereIn('name', array_keys(self::CATEGORIES))->orderBy('id')->get(['id', 'name']);
    }

    public function category(int $id): object
    {
        return $this->categories()->firstWhere('id', $id) ?? abort(404);
    }

    public function sizes(object $category): array
    {
        return self::CATEGORIES[$category->name];
    }

    public function normalizeSize(?string $value, object $category): ?string
    {
        // Беремо лише розмір на початку запису, не довжину устілки. Помилки на кшталт 42/23 не вгадуємо.
        if (! preg_match('/^\s*(\d{2})\s*[\/\-–—]\s*(\d{2})(?=$|[^\d])/u', $value ?? '', $matches)) {
            return null;
        }

        $size = $matches[1].'/'.$matches[2];

        return in_array($size, $this->sizes($category), true) ? $size : null;
    }
}
