<?php

namespace Tests\Unit;

use App\Services\Costs\LaminateRowLayout;
use PHPUnit\Framework\TestCase;

class LaminateRowLayoutTest extends TestCase
{
    public function test_shared_fixtures_cover_rotated_offcuts_without_double_counting(): void
    {
        $calculator = new LaminateRowLayout;
        $fixtures = json_decode(file_get_contents(__DIR__.'/../Fixtures/laminate-rows.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixtures as $fixture) {
            $result = $calculator->calculate(...$fixture['input']);
            foreach ($fixture['expected'] as $key => $value) {
                $this->assertEquals($value, $result[$key], $fixture['name'].': '.$key);
            }
            $this->assertEquals($result['total_pieces'], array_sum(array_column($result['zones'], 'pieces')));
            $this->assertGreaterThanOrEqual(0, $result['offcut_area_m2']);
            $this->assertLessThanOrEqual(100, $result['offcut_percent']);
            $this->assertCount(count(array_unique(array_column($result['zones'], 'key'))), $result['zones']);
            foreach ($result['zones'] as $i => $zone) {
                $this->assertLessThanOrEqual($fixture['input'][0] + 0.000001, $zone['x_cm'] + $zone['width_cm']);
                $this->assertLessThanOrEqual($fixture['input'][1] + 0.000001, $zone['y_cm'] + $zone['height_cm']);
                foreach (array_slice($result['zones'], $i + 1) as $other) {
                    $overlapX = min($zone['x_cm'] + $zone['width_cm'], $other['x_cm'] + $other['width_cm']) - max($zone['x_cm'], $other['x_cm']);
                    $overlapY = min($zone['y_cm'] + $zone['height_cm'], $other['y_cm'] + $other['height_cm']) - max($zone['y_cm'], $other['y_cm']);
                    $this->assertTrue($overlapX <= 0.000001 || $overlapY <= 0.000001, $fixture['name'].': зони перетинаються');
                }
            }
        }
    }
}
