<?php

namespace Tests\Feature\Integration;

use App\Services\Integration\Adapters\CustomAdapter;
use Tests\TestCase;

class CustomAdapterTest extends TestCase
{
    /** Реф-и НП із сайту (provider_*_ref) мають потрапляти в canonical, а не губитись. */
    public function test_maps_provider_refs_to_canonical(): void
    {
        $raw = [
            'external_order_id' => 'X-1',
            'delivery' => [
                'type' => 'warehouse',
                'city_name' => 'Рівне, Рівненська обл.',
                'warehouse_name' => 'Відділення №8',
                'provider_city_ref' => 'db5c896a-391c-11dd-90d9-001a92567626',
                'provider_warehouse_ref' => '5a39e59d-e1c2-11e3-8c4a-0050568002cf',
            ],
        ];

        $canonical = (new CustomAdapter())->normalize($raw);

        $this->assertSame('db5c896a-391c-11dd-90d9-001a92567626', $canonical['delivery']['city_ref']);
        $this->assertSame('5a39e59d-e1c2-11e3-8c4a-0050568002cf', $canonical['delivery']['warehouse_ref']);
    }
}
