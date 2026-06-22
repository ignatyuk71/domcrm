<?php

namespace Tests\Feature\Fiscal;

use App\Models\CheckboxSetting;
use App\Services\CheckboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckboxServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CheckboxSetting::create([
            'api_url' => 'https://api.checkbox.in.ua/api/v1',
            'license_key' => 'TEST-LICENSE',
            'login' => 'test-login',
            'password' => 'test-password',
            'enabled' => true,
        ]);
    }

    /** Закрита зміна (200 + порожньо) має репортитись як CLOSED, не unknown. */
    public function test_closed_shift_reported_as_closed(): void
    {
        Http::fake([
            '*cashier/signin' => Http::response(['access_token' => 'test-token'], 200),
            '*cashier/shift' => Http::response(null, 200), // немає активної зміни
        ]);

        $shift = app(CheckboxService::class)->getCurrentShift();

        $this->assertSame('CLOSED', $shift['status'] ?? null);
    }

    /** Відкрита зміна — статус OPENED. */
    public function test_open_shift_reported_as_opened(): void
    {
        Http::fake([
            '*cashier/signin' => Http::response(['access_token' => 'test-token'], 200),
            '*cashier/shift' => Http::response(['status' => 'OPENED'], 200),
        ]);

        $shift = app(CheckboxService::class)->getCurrentShift();

        $this->assertSame('OPENED', $shift['status'] ?? null);
    }
}
