<?php

namespace Tests\Unit;

use App\Services\Costs\TapeCostCalculator;
use PHPUnit\Framework\TestCase;

class TapeCostCalculatorTest extends TestCase
{
    public function test_shared_synthetic_examples_preserve_currency_and_length_precision(): void
    {
        $calculator = new TapeCostCalculator;
        $fixtures = json_decode(file_get_contents(__DIR__.'/../Fixtures/tape-costs.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixtures as $fixture) {
            $result = $calculator->calculate(1, $calculator->normalize($fixture['input']));
            foreach ($fixture['expected'] as $key => $value) {
                $this->assertEquals($value, $result[$key], $fixture['name'].': '.$key);
            }
        }
    }
}
