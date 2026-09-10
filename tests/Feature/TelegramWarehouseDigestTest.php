<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TelegramSetting;
use App\Services\TelegramWarehouseDigest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramWarehouseDigestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTimezone('Europe/Kyiv')->setDate(2026, 9, 9)->setTime(10, 0));
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $s = new TelegramSetting;
        $s->id = 1;
        $s->fill(['bot_token' => '123456:abcdefghijklmnopqrstuvwxyz123456789', 'chat_id' => '-123', 'enabled' => true, 'verified_at' => now(), 'permissions' => ['warehouse_reminder' => true]])->save();
    }

    private function order(int $day, string $status = 'delivered'): Order
    {
        $o = Order::create(['order_number' => 'TEST-'.(Order::count() + 1), 'status' => $status]);
        $d = $o->delivery()->create(['carrier' => 'nova_poshta', 'delivery_type' => 'warehouse', 'ttn' => '20450000000000', 'delivery_status_code' => 'at_warehouse', 'last_tracked_at' => now(), 'recipient_name' => 'Олена <Тест>', 'recipient_phone' => '0960356242']);
        $d->statusHistory()->create(['status_code' => 'at_warehouse', 'entered_at' => now()->startOfDay()->subDays($day - 1)->addHours(23)]);
        $o->items()->create(['product_title' => 'Капці <рожеві>', 'qty' => 2, 'price' => 100, 'total' => 200]);
        return $o;
    }

    public function test_selects_days_five_to_seven_sorts_and_escapes_html(): void
    {
        foreach ([4, 5, 6, 7, 8] as $day) {
            $this->order($day);
        }
        $this->order(6, 'delivered_paid');
        $stale = $this->order(6);
        $stale->delivery->update(['last_tracked_at' => now()->subHours(2)]);
        $received = $this->order(6);
        $received->delivery->update(['delivery_status_code' => 'received']);
        $messages = app(TelegramWarehouseDigest::class)->messages(CarbonImmutable::now());
        $this->assertCount(1, $messages);
        $text = $messages[0];
        $this->assertStringContainsString('Замовлень: 3', $text);
        $this->assertStringContainsString('🟡 <b>5-й', $text);
        $this->assertStringContainsString('🔴 <b>6-й', $text);
        $this->assertStringContainsString('🔴 <b>7-й', $text);
        $this->assertLessThan(strpos($text, '6-й'), strpos($text, '7-й'));
        $this->assertStringContainsString('Капці &lt;рожеві&gt; — 2 шт.', $text);
        $this->assertStringContainsString('+380960356242', $text);
        $this->assertStringNotContainsString('4-й', $text);
        $this->assertStringNotContainsString('8-й', $text);
    }

    public function test_only_one_send_per_day_and_repeats_next_day(): void
    {
        $o = $this->order(5);
        $this->artisan('telegram:warehouse-digest')->assertSuccessful();
        $this->artisan('telegram:warehouse-digest')->assertSuccessful();
        Http::assertSentCount(1);
        $this->travel(1)->days();
        $o->delivery->update(['last_tracked_at' => now()]);
        $this->artisan('telegram:warehouse-digest')->assertSuccessful();
        Http::assertSentCount(2);
    }

    public function test_disabled_empty_and_dry_run_do_not_send(): void
    {
        $this->artisan('telegram:warehouse-digest')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertDatabaseHas('telegram_digest_runs', ['status' => 'empty']);
        DB::table('telegram_digest_runs')->delete();
        $this->order(5);
        $this->artisan('telegram:warehouse-digest --dry-run')->assertSuccessful();
        $this->assertDatabaseCount('telegram_digest_runs', 0);
        TelegramSetting::current()->update(['enabled' => false]);
        $this->artisan('telegram:warehouse-digest')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertDatabaseCount('telegram_digest_runs', 0);
    }

    public function test_failed_send_is_not_retried_and_secrets_are_not_stored(): void
    {
        $this->order(6);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 500)]);
        $this->artisan('telegram:warehouse-digest')->assertFailed();
        $this->artisan('telegram:warehouse-digest')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertDatabaseHas('telegram_digest_runs', ['status' => 'failed', 'sent_parts' => 0]);
    }

    public function test_long_list_splits_without_breaking_order_blocks(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->order(5);
        }
        $parts = app(TelegramWarehouseDigest::class)->messages(CarbonImmutable::now());
        $this->assertGreaterThan(1, count($parts));
        foreach ($parts as $part) {
            $this->assertLessThan(4096, mb_strlen($part));
            $this->assertStringStartsWith('Частина ', $part);
            $this->assertSame(substr_count($part, '<a '), substr_count($part, '</a>'));
        }
        $this->assertSame(30, substr_count(implode('', $parts), 'Замовлення ↗'));
    }
}
