<?php

namespace Tests\Unit;

use App\Services\Costs\TrapezoidRowLayout;
use PHPUnit\Framework\TestCase;

class TrapezoidRowLayoutTest extends TestCase
{
    public function test_shared_cutting_examples_match_whole_rows_and_offcuts(): void
    {
        $calculator = new TrapezoidRowLayout;
        $fixtures = json_decode(file_get_contents(__DIR__.'/../Fixtures/trapezoid-rows.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixtures as $fixture) {
            $result = $calculator->calculate(...$fixture['input']);
            foreach ($fixture['expected'] as $key => $value) {
                $this->assertEquals($value, $result[$key], $fixture['name'].': '.$key);
            }
            $this->assertGreaterThanOrEqual(0, $result['offcut_area_m2']);
            $this->assertLessThanOrEqual(100, $result['offcut_percent']);
        }
    }
}
