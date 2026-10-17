<?php

namespace Tests\Feature;

use App\Jobs\SyncNovaPay;
use App\Models\NovaPayConnection;
use App\Models\User;
use App\Services\NovaPay\RefreshSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NovaPayScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_scheduler_has_only_three_daily_novapay_sessions_with_reconciliation_in_the_last_one(): void
    {
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $events = array_values(array_filter(app(Schedule::class)->events(), fn ($event) => str_contains($event->command ?? '', 'novapay:sync')));
        $this->assertCount(3, $events);
        $this->assertSame(['30 14 * * *', '15 20 * * *', '45 23 * * *'], array_column($events, 'expression'));

        foreach ($events as $index => $event) {
            $this->assertSame('Europe/Kyiv', $event->timezone);
            $this->assertTrue($event->withoutOverlapping);
            $this->assertTrue($event->onOneServer);
            $this->assertSame($index === 2, str_contains($event->command, '--reconcile'));
        }

        foreach (['04:30', '12:00', '14:29', '14:31', '20:14', '23:44'] as $time) {
            $this->travelTo(\Carbon\Carbon::parse('2026-10-17 '.$time, 'Europe/Kyiv'));
            $this->assertCount(0, array_filter($events, fn ($event) => $event->isDue($this->app)), $time);
        }
        foreach (['14:30', '20:15', '23:45'] as $time) {
            $this->travelTo(\Carbon\Carbon::parse('2026-10-17 '.$time, 'Europe/Kyiv'));
            $this->assertCount(1, array_filter($events, fn ($event) => $event->isDue($this->app)), $time);
        }
        Http::assertNothingSent();
    }

    public static function scheduleDates(): array
    {
        return [
            'ранок після півночі' => ['2026-10-17T01:00:00Z', '2026-10-16T20:45:00Z', '2026-10-17T11:30:00Z'],
            'точний час першого сеансу' => ['2026-10-17T11:30:00Z', '2026-10-17T11:30:00Z', '2026-10-17T17:15:00Z'],
            'після останнього сеансу' => ['2026-10-17T20:50:00Z', '2026-10-17T20:45:00Z', '2026-10-18T11:30:00Z'],
            'перехід на зимовий час' => ['2026-10-25T00:00:00Z', '2026-10-24T20:45:00Z', '2026-10-25T12:30:00Z'],
            'перехід на літній час' => ['2026-03-29T00:00:00Z', '2026-03-28T21:45:00Z', '2026-03-29T11:30:00Z'],
        ];
    }

    #[DataProvider('scheduleDates')]
    public function test_schedule_metadata_observes_kyiv_calendar_including_midnight_and_dst(string $at, string $previous, string $next): void
    {
        $schedule = app(RefreshSchedule::class)->data(CarbonImmutable::parse($at));
        $this->assertSame(['14:30', '20:15', '23:45'], $schedule['times']);
        $this->assertSame('Europe/Kyiv', $schedule['timezone']);
        $this->assertSame(CarbonImmutable::parse($previous)->toIso8601String(), $schedule['previous_at']);
        $this->assertSame(CarbonImmutable::parse($next)->toIso8601String(), $schedule['next_at']);
        $this->assertSame(10, $schedule['grace_minutes']);
    }

    public function test_reading_schedule_does_not_refresh_tokens_and_manual_refresh_still_queues_sync(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-17 12:00:00', 'Europe/Kyiv'));
        $this->actingAs(User::factory()->create(['role' => 'owner', 'is_active' => true]));
        $connection = NovaPayConnection::create([
            'login' => 'schedule-test', 'refresh_token' => 'FAKE-REFRESH', 'public_certificate' => 'FAKE-CERTIFICATE',
            'access_token' => 'FAKE-ACCESS', 'token_expires_at' => now()->addHour(),
        ]);
        $account = $connection->accounts()->create([
            'provider_id' => 49, 'client_id' => 8, 'client_name' => 'Тест',
            'iban' => 'UA29358700000067320000000001', 'currency' => 'UAH',
            'timezone' => 'Europe/Kyiv', 'enabled' => true, 'import_from' => '2026-10-17',
        ]);
        $before = DB::table('novapay_connections')->where('id', $connection->id)->first();
        config(['novapay.operations_verified' => false]);

        $this->getJson('/finance/novapay/data')->assertOk()
            ->assertJsonPath('refresh_schedule.times', ['14:30', '20:15', '23:45'])
            ->assertJsonPath('refresh_schedule.timezone', 'Europe/Kyiv')
            ->assertJsonMissingPath('refresh_minutes');
        Queue::assertNothingPushed();
        $this->postJson('/finance/novapay/accounts/'.$account->id.'/refresh')->assertAccepted()->assertJsonPath('queued', 1);
        Queue::assertPushed(SyncNovaPay::class, 1);
        Http::assertNothingSent();
        $after = DB::table('novapay_connections')->where('id', $connection->id)->first();
        foreach (['refresh_token', 'public_certificate', 'access_token', 'token_expires_at'] as $field) {
            $this->assertSame($before->$field, $after->$field);
        }
    }
}
