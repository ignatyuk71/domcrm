<?php

namespace Tests\Feature;

use App\Models\{NovaPayConnection, NovaPayOperation, NovaPayPayout, NovaPaySyncRun, Order, OrderDelivery, User};
use App\Services\NovaPay\{NovaPayClient, NovaPayException, PayoutImporter, PayoutLedger, PayoutNormalizer, SyncDispatcher, SyncService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http, Queue};
use Tests\TestCase;

class NovaPayPayoutTest extends TestCase
{
    use RefreshDatabase;

    private function account()
    {
        $this->travelTo(now()->setDate(2026, 10, 16)->setTime(12, 0));
        Queue::fake(); Http::preventStrayRequests();
        $connection = NovaPayConnection::create(['login' => 'payout-owner', 'refresh_token' => 'secret', 'public_certificate' => 'secret', 'access_token' => 'secret-jwt', 'token_expires_at' => now()->addMinutes(3)]);
        return $connection->accounts()->create(['provider_id' => 49, 'client_id' => 8, 'client_name' => 'Тест', 'iban' => 'UA290000000000000000000000001', 'currency' => 'UAH', 'timezone' => 'Europe/Kyiv', 'enabled' => true, 'import_from' => '2026-10-01']);
    }

    private function csv(array $overrides = [], bool $duplicate = false): string
    {
        $row = ['Дата перерахунку коштів' => '15.10.2026 03:17', 'Номер реєстру' => 'BO0001', 'Сума принятих коштів' => '500.00', 'Сума утриманої винагороди' => '2.50', 'Сума перерахованих коштів' => '497.50', '№ ЕН НП' => '20450000000001', 'Номер рахунку отримувача' => 'UA290000000000000000000000001', 'Унікальний обліковий номер фінансової операції' => '0000123'];
        $stream = fopen('php://temp', 'r+'); fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, array_keys($row), ',', '"', ''); fputcsv($stream, array_values(array_replace($row, $overrides)), ',', '"', '');
        if ($duplicate) fputcsv($stream, array_values($row), ',', '"', '');
        rewind($stream); $csv = stream_get_contents($stream); fclose($stream); return $csv;
    }

    private function bank($account, string $id = 'bank-1', string $date = '2026-10-15', int $amount = 49750)
    {
        return NovaPayOperation::create(['account_id' => $account->id, 'provider_id' => $id, 'booked_on' => $date, 'direction' => 'in', 'amount_minor' => $amount, 'status' => 'posted', 'source_status' => 'Conducted', 'sender_tax_code' => '38324133', 'fingerprint' => hash('sha256', $id)]);
    }

    private function records($account): array
    {
        return app(PayoutNormalizer::class)->normalize($this->csv(), $account, '2026-10-01', '2026-10-16');
    }

    private function order(string $ttn, string $number = '1001')
    {
        $order = Order::create(['order_number' => $number, 'currency' => 'UAH', 'status' => 'new']);
        OrderDelivery::create(['order_id' => $order->id, 'carrier' => 'nova_poshta', 'delivery_type' => 'warehouse', 'ttn' => $ttn]);
        return $order;
    }

    private function coverage($account): void
    {
        NovaPaySyncRun::create(['connection_id' => $account->connection_id, 'account_id' => $account->id, 'source' => 'payouts', 'date_from' => '2026-10-01', 'date_to' => '2026-10-16', 'status' => 'success', 'succeeded_at' => now()]);
    }

    public function test_exact_ttn_matching_counts_only_bank_confirmed_orders_without_changing_order_payment(): void
    {
        $account = $this->account(); $order = $this->order('20450000000001'); $bank = $this->bank($account);
        $this->coverage($account);
        app(PayoutImporter::class)->import($account, $this->records($account), '2026-10-01', '2026-10-16');
        $this->actingAs(User::factory()->create(['role' => 'owner', 'is_active' => true]));
        $this->getJson('/finance/novapay/data?from=2026-10-01&to=2026-10-16')->assertOk()
            ->assertJsonPath('payouts.paid_orders_count', 1)->assertJsonPath('payouts.matched_net_minor', '49750')
            ->assertJsonPath('payouts.rows.data.0.order_id', $order->id)->assertJsonPath('payouts.rows.data.0.confirmed', true)
            ->assertJsonPath('payouts.rows.data.0.gross_minor', '50000')->assertJsonPath('payouts.rows.data.0.fee_minor', '250');
        $this->assertSame('0000123', NovaPayPayout::first()->provider_id);
        $this->assertSame($bank->id, NovaPayPayout::first()->operation_id);
        $this->assertSame('new', $order->fresh()->status);
        $this->assertDatabaseCount('order_payments', 0); $this->assertDatabaseCount('fiscal_receipts', 0);
        $records = $this->records($account); $extra = $records[0]; $extra['provider_id'] = '0000124'; $records[] = $extra;
        $bank->update(['amount_minor' => 99500]);
        app(PayoutImporter::class)->import($account, $records, '2026-10-01', '2026-10-16');
        $this->assertSame(1, app(PayoutLedger::class)->data($account, '2026-10-01', '2026-10-16')['paid_orders_count']);
    }

    public function test_repeated_import_is_idempotent_and_corrections_or_missing_rows_keep_audit(): void
    {
        $account = $this->account(); $records = $this->records($account); $importer = app(PayoutImporter::class);
        $importer->import($account, $records, '2026-10-01', '2026-10-16'); $importer->import($account, $records, '2026-10-01', '2026-10-16');
        $this->assertDatabaseCount('novapay_payouts', 1); $this->assertDatabaseCount('novapay_payout_revisions', 0);
        $older = $records; $older[0]['ttn'] = '20450000000009';
        $importer->import($account, $older, '2026-10-01', '2026-10-16', now()->subHour()->format('Y-m-d H:i:s'));
        $this->assertSame('20450000000001', NovaPayPayout::first()->ttn);
        $this->assertDatabaseCount('novapay_payout_revisions', 0);
        $records[0]['ttn'] = '20450000000002'; $importer->import($account, $records, '2026-10-01', '2026-10-16');
        $this->assertDatabaseCount('novapay_payout_revisions', 1);
        $importer->import($account, [], '2026-10-01', '2026-10-16');
        $this->assertFalse(NovaPayPayout::first()->active); $this->assertDatabaseCount('novapay_payout_revisions', 2);
    }

    public function test_ambiguous_ttn_and_bank_matches_are_never_presented_as_paid_orders(): void
    {
        $account = $this->account(); $order = $this->order('20450000000001'); $bank = $this->bank($account); $this->coverage($account);
        $importer = app(PayoutImporter::class); $importer->import($account, $this->records($account), '2026-10-01', '2026-10-16');
        $second = $this->order('20450000000001', '1002');
        $data = app(PayoutLedger::class)->data($account, '2026-10-01', '2026-10-16');
        $this->assertSame(0, $data['paid_orders_count']); $this->assertSame(1, $data['ambiguous']);
        $second->delivery()->update(['ttn' => '20450000000002']);
        $this->assertSame(1, app(PayoutLedger::class)->data($account, '2026-10-01', '2026-10-16')['paid_orders_count']);
        $this->bank($account, 'bank-duplicate'); $importer->import($account, $this->records($account), '2026-10-01', '2026-10-16');
        $this->assertNull(NovaPayPayout::first()->operation_id);
        $this->assertSame(0, app(PayoutLedger::class)->data($account, '2026-10-01', '2026-10-16')['paid_orders_count']);
        $this->assertNull(app(PayoutLedger::class)->data($account, '2026-09-01', '2026-09-30')['paid_orders_count']);
    }

    public function test_invalid_csv_never_imports_partial_data_or_mixes_accounts(): void
    {
        $account = $this->account(); $normalizer = app(PayoutNormalizer::class);
        $this->assertSame([], $normalizer->normalize($this->csv(['Номер рахунку отримувача' => 'UA290000000000000000000000002']), $account, '2026-10-01', '2026-10-16'));
        foreach ([$this->csv([], true), $this->csv(['Сума перерахованих коштів' => '499.99']), $this->csv(['Дата перерахунку коштів' => '31.02.2026 03:17']), $this->csv(['№ ЕН НП' => '=123']), $this->csv(['Сума утриманої винагороди' => '0.001']), 'wrong,header'] as $csv) {
            try { $normalizer->normalize($csv, $account, '2026-10-01', '2026-10-16'); $this->fail('Некоректний реєстр прийнято.'); }
            catch (NovaPayException $e) { $this->assertContains($e->reason, ['register_contract', 'invalid_amount']); }
        }
        $this->assertDatabaseCount('novapay_payouts', 0);
    }

    private function soap(string $method, string $fields): string
    {
        return '<Envelope><Body><'.$method.'Result><result>ok</result>'.$fields.'</'.$method.'Result></Body></Envelope>';
    }

    public function test_async_register_reuses_statement_and_downloads_only_trusted_storage_without_jwt(): void
    {
        $account = $this->account(); config(['novapay.payouts_verified' => true]);
        $this->bank($account); $this->order('20450000000001');
        Http::fake([
            '*ClientAPIService.svc' => Http::sequence()->push($this->soap('GetRegister', '<statement_id>7</statement_id>'))
                ->push($this->soap('DownloadRegister', '<status>created</status><file_type>CSV</file_type><url>https://storage.googleapis.com/prod-novaclients3-cod-statements/test.csv</url>')),
            'https://storage.googleapis.com/*' => Http::response($this->csv()),
        ]);
        $run = NovaPaySyncRun::create(['connection_id' => $account->connection_id, 'account_id' => $account->id, 'source' => 'payouts', 'date_from' => '2026-10-01', 'date_to' => '2026-10-16']);
        try { app(SyncService::class)->execute($run); $this->fail('Очікувалась повторна спроба.'); }
        catch (NovaPayException $e) { $this->assertSame('register_pending', $e->reason); $this->assertTrue($e->retryable); }
        $this->assertSame(7, (int) $run->fresh()->statement_id);
        app(SyncService::class)->execute($run->fresh());
        $this->assertSame('success', $run->fresh()->status); $this->assertDatabaseCount('novapay_payouts', 1);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'storage.googleapis.com') && ! $r->hasHeader('Authorization') && ! str_contains($r->body(), 'secret'));
        Http::assertSentCount(3);
    }

    public function test_untrusted_download_url_is_rejected_before_any_download(): void
    {
        $account = $this->account();
        Http::fake(['*ClientAPIService.svc' => Http::response($this->soap('DownloadRegister', '<status>created</status><file_type>CSV</file_type><url>https://evil.example/steal</url>'))]);
        try { app(NovaPayClient::class)->payoutRegister($account, 7); $this->fail('Непідтверджена адреса прийнята.'); }
        catch (NovaPayException $e) { $this->assertSame('register_download', $e->reason); }
        Http::assertSentCount(1);
    }
}
