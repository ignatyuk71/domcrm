<?php

namespace Tests\Feature;

use App\Jobs\SyncNovaPay;
use App\Models\NovaPayAccount;
use App\Models\NovaPayConnection;
use App\Models\NovaPaySyncRun;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NovaPayManualRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-17 12:00:00', 'Europe/Kyiv'));
        Http::preventStrayRequests();
        Queue::fake();
        config(['novapay.operations_verified' => true, 'novapay.payouts_verified' => true]);
        $this->actingAs(User::factory()->create(['role' => 'owner', 'is_active' => true]));
    }

    private function account(): NovaPayAccount
    {
        $connection = NovaPayConnection::create([
            'login' => 'manual-refresh-test', 'refresh_token' => 'FAKE-REFRESH', 'public_certificate' => 'FAKE-CERTIFICATE',
            'access_token' => 'FAKE-ACCESS', 'token_expires_at' => now()->addHour(),
        ]);

        return $connection->accounts()->create([
            'provider_id' => 49, 'client_id' => 8, 'client_name' => 'Тест',
            'iban' => 'UA29358700000067320000000001', 'currency' => 'UAH',
            'timezone' => 'Europe/Kyiv', 'enabled' => true, 'import_from' => '2026-10-01',
        ]);
    }

    private function assertNoCredentialOrProviderChanges(object $before): void
    {
        $this->assertEquals($before, DB::table('novapay_connections')->where('id', $before->id)->first());
        Http::assertNothingSent();
    }

    public static function manualFilters(): array
    {
        return [
            'без фільтрів' => ['', []],
            'історія в query' => ['?from=2026-10-01&to=2026-10-17&direction=out&reconcile=1', []],
            'історія в body та інший період у query' => [
                '?from=2026-01-01&to=2026-12-31&direction=all&reconcile=true',
                ['from' => '2026-10-01', 'to' => '2026-10-16', 'direction' => 'in', 'reconcile' => true],
            ],
        ];
    }

    #[DataProvider('manualFilters')]
    public function test_manual_refresh_queues_only_balance_and_today_even_with_history_gaps_and_period_filters(string $query, array $body): void
    {
        $account = $this->account();
        $before = DB::table('novapay_connections')->where('id', $account->connection_id)->first();

        $this->postJson('/finance/novapay/accounts/'.$account->id.'/refresh'.$query, $body)
            ->assertAccepted()->assertJsonPath('queued', 2);

        $runs = NovaPaySyncRun::query()->orderBy('id')->get();
        $this->assertCount(2, $runs);
        $this->assertSame([
            ['balance', null, null], ['operations', '2026-10-17', '2026-10-17'],
        ], $runs->map(fn ($run) => [$run->source, $run->date_from?->toDateString(), $run->date_to?->toDateString()])->all());
        foreach ($runs as $run) {
            $this->assertSame($account->id, $run->account_id);
            $this->assertSame($account->connection_id, $run->connection_id);
            $this->assertSame('queued', $run->status);
        }
        Queue::assertPushed(SyncNovaPay::class, 2);
        $this->assertNoCredentialOrProviderChanges($before);
    }

    public function test_manual_date_uses_kyiv_after_utc_midnight_boundary_and_repeated_click_does_not_duplicate_jobs(): void
    {
        $this->travelTo(Carbon::parse('2026-10-17 22:30:00', 'UTC'));
        $account = $this->account();
        $before = DB::table('novapay_connections')->where('id', $account->connection_id)->first();
        $url = '/finance/novapay/accounts/'.$account->id.'/refresh';

        $this->postJson($url)->assertAccepted()->assertJsonPath('queued', 2);
        $this->postJson($url)->assertAccepted()->assertJsonPath('queued', 0);

        $this->assertDatabaseCount('novapay_sync_runs', 2);
        $this->assertDatabaseHas('novapay_sync_runs', [
            'account_id' => $account->id, 'source' => 'operations', 'date_from' => '2026-10-18', 'date_to' => '2026-10-18', 'status' => 'queued',
        ]);
        $this->assertDatabaseMissing('novapay_sync_runs', ['source' => 'operations', 'date_from' => '2026-10-17']);
        $this->assertDatabaseMissing('novapay_sync_runs', ['source' => 'payouts']);
        Queue::assertPushed(SyncNovaPay::class, 2);
        $this->assertNoCredentialOrProviderChanges($before);
    }

    public function test_manual_refresh_leaves_existing_history_and_payout_tasks_untouched(): void
    {
        $account = $this->account();
        $before = DB::table('novapay_connections')->where('id', $account->connection_id)->first();
        foreach ([['operations', '2026-10-03', '2026-10-03'], ['payouts', '2026-10-01', '2026-10-17']] as [$source, $from, $to]) {
            NovaPaySyncRun::create([
                'account_id' => $account->id, 'connection_id' => $account->connection_id,
                'source' => $source, 'date_from' => $from, 'date_to' => $to, 'status' => 'queued',
            ]);
        }
        $existing = DB::table('novapay_sync_runs')->orderBy('id')->get()->toArray();
        $existingIds = array_column($existing, 'id');

        $this->postJson('/finance/novapay/accounts/'.$account->id.'/refresh')->assertAccepted()->assertJsonPath('queued', 2);

        $this->assertEquals($existing, DB::table('novapay_sync_runs')->whereIn('id', $existingIds)->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('novapay_sync_runs', 4);
        $new = NovaPaySyncRun::query()->whereNotIn('id', $existingIds)->orderBy('id')->get();
        $this->assertSame(['balance', 'operations'], $new->pluck('source')->all());
        $this->assertSame('2026-10-17', $new->last()->date_from->toDateString());
        $this->assertSame(1, NovaPaySyncRun::where('source', 'payouts')->count());
        Queue::assertPushed(SyncNovaPay::class, 2);
        $this->assertNoCredentialOrProviderChanges($before);
    }

    public static function unavailableAccounts(): array
    {
        return ['вимкнений рахунок' => [false, false], 'потрібна авторизація' => [true, true]];
    }

    #[DataProvider('unavailableAccounts')]
    public function test_manual_refresh_rejects_disabled_or_unauthorized_account_without_enqueuing(bool $enabled, bool $requiresAuth): void
    {
        $account = $this->account();
        $account->update(['enabled' => $enabled]);
        $account->connection->update(['requires_auth' => $requiresAuth]);
        $before = DB::table('novapay_connections')->where('id', $account->connection_id)->first();

        $this->postJson('/finance/novapay/accounts/'.$account->id.'/refresh')->assertUnprocessable();

        $this->assertDatabaseCount('novapay_sync_runs', 0);
        Queue::assertNothingPushed();
        $this->assertNoCredentialOrProviderChanges($before);
    }

    public function test_planned_reconciliation_still_backfills_history_and_payouts(): void
    {
        $account = $this->account();
        $before = DB::table('novapay_connections')->where('id', $account->connection_id)->first();
        NovaPaySyncRun::create([
            'account_id' => $account->id, 'connection_id' => $account->connection_id,
            'source' => 'operations', 'date_from' => '2026-10-16', 'date_to' => '2026-10-16',
            'status' => 'success', 'succeeded_at' => now()->subDay(),
        ]);

        $this->artisan('novapay:sync', ['--reconcile' => true])->assertSuccessful();

        $this->assertSame(17, NovaPaySyncRun::where('source', 'operations')->where('status', 'queued')->count());
        foreach (['2026-10-01', '2026-10-16', '2026-10-17'] as $date) {
            $this->assertDatabaseHas('novapay_sync_runs', ['account_id' => $account->id, 'source' => 'operations', 'date_from' => $date, 'status' => 'queued']);
        }
        $this->assertDatabaseHas('novapay_sync_runs', [
            'account_id' => $account->id, 'source' => 'payouts', 'date_from' => '2026-10-01', 'date_to' => '2026-10-17', 'status' => 'queued',
        ]);
        $this->assertSame(1, NovaPaySyncRun::where('source', 'balance')->where('status', 'queued')->count());
        $this->assertDatabaseMissing('novapay_sync_runs', ['source' => 'discovery']);
        Queue::assertPushed(SyncNovaPay::class, 19);
        $this->assertNoCredentialOrProviderChanges($before);
    }
}
