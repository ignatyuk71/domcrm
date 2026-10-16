<?php

namespace Tests\Feature;

use App\Jobs\SyncNovaPay;
use App\Models\NovaPayAccount;
use App\Models\NovaPayBalance;
use App\Models\NovaPayConnection;
use App\Models\NovaPayOperation;
use App\Models\NovaPaySyncRun;
use App\Models\User;
use App\Services\NovaPay\ExtractNormalizer;
use App\Services\NovaPay\Money;
use App\Services\NovaPay\NovaPayException;
use App\Services\NovaPay\OperationImporter;
use App\Services\NovaPay\SyncDispatcher;
use App\Services\NovaPay\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Queue\QueueManager;
use Tests\TestCase;

class NovaPayTest extends TestCase
{
    use RefreshDatabase;

    private QueueManager $queueManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-05 12:00:00', 'Europe/Kyiv'));
        Http::preventStrayRequests();
        $this->queueManager = Queue::getFacadeRoot();
        Queue::fake();
        config(['novapay.operations_verified' => false]);
    }

    private function owner(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner', 'is_active' => true]));
    }

    private function account(): NovaPayAccount
    {
        $connection = NovaPayConnection::create([
            'login' => 'owner-test', 'refresh_token' => 'SECRET-REFRESH', 'public_certificate' => 'SECRET-CERTIFICATE',
            'access_token' => 'SECRET-JWT', 'token_expires_at' => now()->addMinutes(3),
        ]);

        return $connection->accounts()->create([
            'provider_id' => 49, 'client_id' => 8, 'client_name' => 'ФОП Тест',
            'iban' => 'UA29358700000067320000000001', 'currency' => 'UAH',
            'timezone' => 'Europe/Kyiv', 'enabled' => true, 'import_from' => '2026-10-04',
        ]);
    }

    private function syncRun(NovaPayAccount $account, string $source = 'balance', ?string $date = null): NovaPaySyncRun
    {
        return NovaPaySyncRun::create([
            'connection_id' => $account->connection_id, 'account_id' => $account->id, 'source' => $source,
            'date_from' => $date, 'date_to' => $date,
        ]);
    }

    private function soap(string $method, string $fields): string
    {
        return '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><'.$method.'Response xmlns="http://tempuri.org/"><'.$method.'Result><result>ok</result>'.$fields.'</'.$method.'Result></'.$method.'Response></s:Body></s:Envelope>';
    }

    private function operation(string $id, int $minor, string $direction = 'in', string $status = 'posted'): array
    {
        return [
            'provider_id' => $id, 'booked_on' => '2026-10-05', 'amount_minor' => $minor,
            'direction' => $direction, 'status' => $status, 'source_status' => 'test',
            'counterparty' => 'NovaPay', 'purpose' => 'Зведене зарахування',
        ];
    }

    private function paymentsPayload(NovaPayAccount $account, array $rows): string
    {
        $xml = '<Payments>';
        foreach ($rows as $row) {
            $incoming = ($row['direction'] ?? 'in') === 'in';
            $other = 'UA29358700000067320000000002';
            $fields = [
                'ID' => $row['id'] ?? 'doc-1', 'DayDate' => $row['date'] ?? '05.10.2026',
                'Code' => $row['number'] ?? '000123A0',
                'PayDate' => $incoming ? '05.10.2026' : '',
                'Changed' => $row['changed'] ?? '05.10.2026 14:19:50',
                'StatusDocument' => $row['status'] ?? 'Conducted',
                'CreditCodeIBAN' => $incoming ? $account->iban : $other,
                'DebitCodeIBAN' => $incoming ? $other : $account->iban,
                'CreditName' => 'Отримувач', 'DebitName' => 'Відправник', 'Purpose' => 'Тестова операція',
                'DebitStateCode' => '0000000001', 'CreditStateCode' => '0000000002',
            ];
            $xml .= '<Docs Amount="'.($row['amount'] ?? '3913.31').'" CurrencyTag="UAH">';
            foreach ($fields as $key => $value) {
                $xml .= '<'.$key.'>'.htmlspecialchars($value, ENT_XML1).'</'.$key.'>';
            }
            $xml .= '</Docs>';
        }

        return $xml.'</Payments>';
    }

    public function test_only_active_owner_can_view_or_modify_novapay(): void
    {
        foreach (['operator', 'packer'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'is_active' => true]));
            $this->getJson('/finance/novapay/data')->assertForbidden();
            $this->postJson('/finance/novapay/connections', [])->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'owner', 'is_active' => false]));
        $this->getJson('/finance/novapay/data')->assertForbidden();
    }

    public function test_page_and_empty_state_do_not_call_provider_or_report_zero(): void
    {
        $this->owner();
        $this->withoutVite();
        $this->get('/finance/novapay')->assertOk()->assertSee('crm-novapay');
        $this->getJson('/finance/novapay/data')->assertOk()->assertJsonPath('balance', null)->assertJsonPath('incoming_minor', null);
        Http::assertNothingSent();
    }

    public function test_credentials_are_encrypted_and_not_returned_to_browser(): void
    {
        $this->owner();
        $this->postJson('/finance/novapay/connections', [
            'login' => 'new-owner', 'refresh_token' => 'SECRET-REFRESH', 'public_certificate' => 'SECRET-CERTIFICATE',
        ])->assertAccepted();
        $connection = NovaPayConnection::firstOrFail();
        $this->assertNotSame('SECRET-REFRESH', DB::table('novapay_connections')->value('refresh_token'));
        $this->assertNotSame('SECRET-CERTIFICATE', DB::table('novapay_connections')->value('public_certificate'));
        $this->assertSame('SECRET-REFRESH', $connection->refresh_token);
        $this->assertArrayNotHasKey('refresh_token', $connection->toArray());
        $this->getJson('/finance/novapay/data')->assertOk()->assertDontSee('SECRET')->assertDontSee('new-owner');
        Queue::assertPushed(SyncNovaPay::class, 1);
        Http::assertNothingSent();
    }

    public function test_repeated_refresh_does_not_create_duplicate_jobs(): void
    {
        $this->owner();
        $account = $this->account();
        $this->postJson('/finance/novapay/accounts/'.$account->id.'/refresh')->assertAccepted()->assertJsonPath('queued', 1);
        $this->postJson('/finance/novapay/accounts/'.$account->id.'/refresh')->assertAccepted()->assertJsonPath('queued', 0);
        $this->assertDatabaseCount('novapay_sync_runs', 1);
        Queue::assertPushed(SyncNovaPay::class, 1);
    }

    public function test_sync_default_still_stores_novapay_job_in_background(): void
    {
        $this->owner();
        $account = $this->account();
        config(['queue.default' => 'sync']);
        Queue::swap($this->queueManager);

        $this->postJson('/finance/novapay/accounts/'.$account->id.'/refresh')
            ->assertAccepted()->assertJsonPath('queued', 1);

        $this->assertDatabaseHas('jobs', ['queue' => 'novapay']);
        $this->assertDatabaseHas('novapay_sync_runs', ['source' => 'balance', 'status' => 'queued']);
        Http::assertNothingSent();
    }

    public function test_balance_preserves_exact_minor_units_and_ignores_period(): void
    {
        $this->owner();
        $account = $this->account();
        Http::fake(['*' => Http::response($this->soap('GetAccountRest', '<available_balance>9950.0100</available_balance><confirmed_balance>10000.0000</confirmed_balance><projected_balance>11000.00</projected_balance>'))]);
        (new SyncNovaPay($this->syncRun($account)->id))->handle(app(SyncService::class));
        $this->getJson('/finance/novapay/data?from=2026-09-01&to=2026-09-30')
            ->assertOk()->assertJsonPath('balance.amount_minor', '995001')->assertJsonPath('balance.type', 'available');
        $this->assertDatabaseCount('novapay_balances', 3);
        $this->assertDatabaseHas('novapay_sync_runs', ['source' => 'balance', 'status' => 'success']);
    }

    public function test_provider_failure_keeps_last_balance_and_redacts_error_body(): void
    {
        $account = $this->account();
        NovaPayBalance::create(['account_id' => $account->id, 'type' => 'available', 'amount_minor' => 995000, 'received_at' => now()->subMinutes(5)]);
        Http::fake(['*' => Http::response('SECRET-JWT SECRET-REFRESH', 503)]);
        $run = $this->syncRun($account);
        try {
            (new SyncNovaPay($run->id))->handle(app(SyncService::class));
            $this->fail('Тимчасову помилку потрібно повторювати.');
        } catch (NovaPayException $exception) {
            $this->assertTrue($exception->retryable);
            $this->assertStringNotContainsString('SECRET', $exception->getMessage());
        }
        $this->assertSame(995000, NovaPayBalance::first()->amount_minor);
        $this->assertSame('retrying', $run->fresh()->status);
        $this->assertNull($run->fresh()->succeeded_at);
    }

    public function test_invalid_balance_does_not_partially_replace_balances(): void
    {
        $account = $this->account();
        NovaPayBalance::create(['account_id' => $account->id, 'type' => 'available', 'amount_minor' => 100, 'received_at' => now()]);
        Http::fake(['*' => Http::response($this->soap('GetAccountRest', '<available_balance>9950.01</available_balance><confirmed_balance>1.2345</confirmed_balance><projected_balance>1.00</projected_balance>'))]);
        $run = $this->syncRun($account);
        (new SyncNovaPay($run->id))->handle(app(SyncService::class));
        $this->assertSame(100, NovaPayBalance::first()->amount_minor);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('invalid_amount', $run->fresh()->error_code);
    }

    public function test_import_is_idempotent_and_corrections_keep_history(): void
    {
        $account = $this->account();
        $importer = app(OperationImporter::class);
        $record = $this->operation('bank-1', 995000);
        $importer->import($account, [$record]);
        $importer->import($account, [$record]);
        $this->assertDatabaseCount('novapay_operations', 1);
        $this->assertDatabaseCount('novapay_operation_revisions', 0);
        $importer->import($account, [[...$record, 'status' => 'cancelled']]);
        $this->assertDatabaseCount('novapay_operations', 1);
        $this->assertDatabaseCount('novapay_operation_revisions', 1);
        $this->assertSame('cancelled', NovaPayOperation::first()->status);
    }

    public function test_totals_use_posted_incoming_and_require_complete_dates(): void
    {
        $this->owner();
        $account = $this->account();
        app(OperationImporter::class)->import($account, [
            $this->operation('incoming', 995000), $this->operation('outgoing', 5000, 'out'),
            $this->operation('pending', 20000, 'in', 'pending'), $this->operation('cancelled', 30000, 'in', 'cancelled'),
        ]);
        $this->getJson('/finance/novapay/data')->assertOk()->assertJsonPath('incoming_minor', null);
        $run = $this->syncRun($account, 'operations', '2026-10-05');
        $run->update(['status' => 'success', 'succeeded_at' => now()]);
        $this->getJson('/finance/novapay/data')->assertOk()->assertJsonPath('incoming_minor', '995000')
            ->assertJsonPath('coverage_complete', true)->assertJsonPath('operations.total', 3);
        $this->getJson('/finance/novapay/data?from=2026-10-04&to=2026-10-05')->assertOk()->assertJsonPath('incoming_minor', null);
        $this->getJson('/finance/novapay/data?direction=out')->assertOk()->assertJsonPath('incoming_minor', '995000')->assertJsonPath('operations.total', 1);
    }

    public function test_pagination_and_account_isolation(): void
    {
        $this->owner();
        $account = $this->account();
        $records = [];
        for ($i = 1; $i <= 30; $i++) $records[] = $this->operation('bank-'.$i, 100);
        app(OperationImporter::class)->import($account, $records);
        $other = $account->replicate();
        $other->provider_id = 50;
        $other->save();
        app(OperationImporter::class)->import($other, [$this->operation('other-only', 999999)]);
        $this->getJson('/finance/novapay/data?account_id='.$account->id)->assertOk()
            ->assertJsonPath('operations.total', 30)->assertJsonCount(25, 'operations.data');
        $this->getJson('/finance/novapay/data?account_id='.$account->id.'&page=2')->assertJsonCount(5, 'operations.data');
        $this->getJson('/finance/novapay/data?account_id=999999')->assertNotFound();
    }

    public function test_gap_recovery_enqueues_missing_days_without_duplicate_runs(): void
    {
        config(['novapay.operations_verified' => true]);
        $account = $this->account();
        $account->update(['import_from' => '2026-09-25']);
        $run = $this->syncRun($account, 'operations', '2026-10-04');
        $run->update(['status' => 'success', 'succeeded_at' => now()]);
        $dispatcher = app(SyncDispatcher::class);
        $this->assertSame(11, $dispatcher->refresh($account));
        $this->assertSame(0, $dispatcher->refresh($account));
        $this->assertDatabaseHas('novapay_sync_runs', ['date_from' => '2026-09-25', 'status' => 'queued']);
        $this->assertSame(1, NovaPaySyncRun::where('date_from', '2026-10-04')->count());
    }

    public function test_unknown_extract_status_rejects_entire_response(): void
    {
        $account = $this->account();
        $xml = $this->paymentsPayload($account, [['status' => 'unknown']]);
        $this->expectException(NovaPayException::class);
        app(ExtractNormalizer::class)->normalize($xml, $account, '2026-10-05', '2026-10-05');
    }

    public function test_real_payment_contract_imports_both_directions_without_outgoing_pay_date(): void
    {
        $this->owner();
        config(['novapay.operations_verified' => true, 'app.timezone' => 'Europe/Kyiv']);
        $account = $this->account();
        $payload = $this->paymentsPayload($account, [
            ['id' => 'in-1'],
            ['id' => 'out-1', 'direction' => 'out', 'amount' => '2000.00', 'changed' => '05.10.2026 14:22:55'],
        ]);
        Http::fake(['*' => Http::response($this->soap('GetPaymentsList', '<payments>'.htmlspecialchars($payload, ENT_XML1).'</payments>'))]);
        $run = $this->syncRun($account, 'operations', '2026-10-05');
        (new SyncNovaPay($run->id))->handle(app(SyncService::class));
        $this->assertSame('success', $run->fresh()->status);
        $this->assertDatabaseCount('novapay_operations', 2);
        Http::assertSent(fn ($request) => str_contains($request->body(), '<GetPaymentsList')
            && str_contains($request->body(), '<date_type>0</date_type>'));

        $response = $this->getJson('/finance/novapay/data?direction=all')->assertOk()
            ->assertJsonPath('incoming_minor', '391331')->assertJsonPath('operations.total', 2)
            ->assertJsonPath('operations.data.0.direction', 'out')
            ->assertJsonPath('operations.data.0.status_changed_at', '2026-10-05T11:22:55+00:00')
            ->assertJsonPath('operations.data.0.counterparty_iban', 'UA2935 •••• 0002');
        $response->assertJsonPath('operations.data.0.document_number', '000123A0')
            ->assertJsonPath('operations.data.0.sender.name', 'Відправник')
            ->assertJsonPath('operations.data.0.sender.iban', 'UA 29 358700 •••• 0001')
            ->assertJsonPath('operations.data.0.sender.tax_code', '0000000001')
            ->assertJsonPath('operations.data.0.recipient.name', 'Отримувач')
            ->assertJsonPath('operations.data.0.recipient.iban', 'UA 29 358700 •••• 0002');
        $response->assertDontSee('UA29358700000067320000000002');
        $response->assertDontSee($account->iban);
        $this->getJson('/finance/novapay/data?direction=out')->assertJsonPath('operations.total', 1);
        $this->getJson('/finance/novapay/data?direction=all&search=000123A0')->assertJsonPath('operations.total', 2);
        $this->getJson('/finance/novapay/data?direction=all&search='.urlencode('Отримувач'))->assertJsonPath('operations.total', 2);

        (new SyncNovaPay($this->syncRun($account, 'operations', '2026-10-05')->id))->handle(app(SyncService::class));
        $this->assertDatabaseCount('novapay_operations', 2);
        $this->assertDatabaseCount('novapay_operation_revisions', 0);
    }

    public function test_unknown_status_does_not_import_part_of_a_period_or_confirm_coverage(): void
    {
        config(['novapay.operations_verified' => true]);
        $account = $this->account();
        $payload = $this->paymentsPayload($account, [['id' => 'valid'], ['id' => 'new-status', 'status' => 'Unverified']]);
        Http::fake(['*' => Http::response($this->soap('GetPaymentsList', '<payments>'.htmlspecialchars($payload, ENT_XML1).'</payments>'))]);
        $run = $this->syncRun($account, 'operations', '2026-10-05');
        (new SyncNovaPay($run->id))->handle(app(SyncService::class));
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertNull($run->fresh()->succeeded_at);
        $this->assertDatabaseCount('novapay_operations', 0);
    }

    public function test_duplicate_ids_invalid_dates_and_unexpected_sections_are_not_silent_empty_periods(): void
    {
        $account = $this->account();
        $payloads = [
            $this->paymentsPayload($account, [['id' => 'same'], ['id' => 'same']]),
            $this->paymentsPayload($account, [['date' => '31.02.2026']]),
            $this->paymentsPayload($account, [['date' => '06.10.2026']]),
            $this->paymentsPayload($account, [['changed' => '05.10.2026 25:00:00']]),
            '<Payments><Error>Не всі дані отримано</Error></Payments>',
        ];
        foreach ($payloads as $payload) {
            try {
                app(ExtractNormalizer::class)->normalize($payload, $account, '2026-10-05', '2026-10-05');
                $this->fail('Некоректний період не повинен вважатися повним.');
            } catch (NovaPayException $e) {
                $this->assertSame('extract_contract', $e->reason);
            }
        }
        $this->assertSame([], app(ExtractNormalizer::class)->normalize('<Payments/>', $account, '2026-10-05', '2026-10-05'));
    }

    public function test_non_iban_counterparty_reference_does_not_discard_a_valid_payment(): void
    {
        $account = $this->account();
        $payload = str_replace('UA29358700000067320000000002', '29090000000001', $this->paymentsPayload($account, [['direction' => 'out']]));
        $records = app(ExtractNormalizer::class)->normalize($payload, $account, '2026-10-05', '2026-10-05');
        $this->assertCount(1, $records);
        $this->assertSame('out', $records[0]['direction']);
        $this->assertSame(391331, $records[0]['amount_minor']);
        $this->assertNull($records[0]['counterparty_iban']);
        $this->assertSame('Отримувач', $records[0]['counterparty']);
    }

    public function test_money_never_uses_float_or_rounds_fractional_kopecks(): void
    {
        $this->assertSame(995001, Money::minor('9950.0100'));
        $this->assertSame(-123, Money::minor('-1.23'));
        $this->assertSame(9999999999999999, Money::minor('99999999999999.99'));
        $this->expectException(NovaPayException::class);
        Money::minor('1.234');
    }
}
