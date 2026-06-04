<?php

namespace App\Services\Integration;

use App\Models\OrderSource;
use App\Services\Integration\Adapters\CustomAdapter;
use App\Services\Integration\Adapters\OrderAdapter;
use RuntimeException;

class AdapterFactory
{
    public static function for(OrderSource $source): OrderAdapter
    {
        return match ($source->adapter) {
            'custom', null, '' => new CustomAdapter(),
            // 'shopify'  => new ShopifyAdapter(),   // Фаза 3
            // 'opencart' => new OpenCartAdapter(),  // Фаза 3
            // 'prom'     => new PromAdapter(),      // Фаза 3
            default => throw new RuntimeException("Адаптер '{$source->adapter}' ще не реалізовано."),
        };
    }
}
