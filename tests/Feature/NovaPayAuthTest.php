<?php

namespace Tests\Feature;

use App\Jobs\SyncNovaPay;
use App\Models\{NovaPayBalance, NovaPayConnection, NovaPaySyncRun, User};
use App\Services\NovaPay\{AuthTokenJournal, NovaPayClient, NovaPayException, SyncDispatcher, SyncService};
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\{DB, File, Http, Queue};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NovaPayAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-17 09:00:00', 'Europe/Kyiv'));
        Http::preventStrayRequests(); Queue::fake();
        $directory = sys_get_temp_dir().'/novapay-auth-test-'.\Illuminate\Support\Str::uuid();
        config(['novapay.auth_journal_directory' => $directory]);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($directory));
    }

    private function account()
    {
        $connection = NovaPayConnection::create(['login' => 'auth-test', 'refresh_token' => 'SECRET-REFRESH', 'public_certificate' => 'SECRET-CERT']);
        return $connection->accounts()->create(['provider_id' => 49, 'client_id' => 8, 'client_name' => 'Тест',
            'iban' => 'UA290000000000000000000000001', 'currency' => 'UAH', 'timezone' => 'Europe/Kyiv', 'enabled' => true]);
    }

    private function soap(string $method, string $fields, string $result = 'ok'): string
    {
        return '<Envelope><Body><'.$method.'Result><result>'.$result.'</result>'.$fields.'</'.$method.'Result></Body></Envelope>';
    }

    private function jwt(int $seconds): string
    {
        return 'header.'.rtrim(strtr(base64_encode(json_encode(['exp' => now()->getTimestamp() + $seconds])), '+/', '-_'), '=').'.signature';
    }

    private function auth(string $jwt): string
    {
        return $this->soap('UserAuthenticationJWT', '<jwt>'.$jwt.'</jwt><refresh_token>NEW-REFRESH</refresh_token><public_certificate>NEW-CERT</public_certificate>');
    }

    private function balance(): string
    {
        return $this->soap('GetAccountRest', '<available_balance>1.00</available_balance><confirmed_balance>1.00</confirmed_balance><projected_balance>1.00</projected_balance>');
    }

    public static function authFailures(): array
    {
        return ['таймаут' => ['timeout', 'auth_timeout'], 'мережа' => ['network', 'auth_network'],
            'HTTP 503' => ['http', 'auth_provider_http'], 'неповна відповідь' => ['incomplete', 'auth_incomplete_response'],
            'відхилена відповідь' => ['result', 'auth_provider_result'], 'відхилений доступ' => ['denied', 'authorization']];
    }

    #[DataProvider('authFailures')]
    public function test_auth_failure_retains_safe_cause_credentials_and_old_balance_without_replaying_rotation(string $kind, string $reason): void
    {
        $account = $this->account(); $connection = $account->connection;
        $stored = DB::table('novapay_connections')->find($connection->id);
        NovaPayBalance::create(['account_id' => $account->id, 'type' => 'available', 'amount_minor' => 995000, 'received_at' => now()->subMinutes(5)]);
        $requests = 0;
        Http::fake(function () use ($kind, &$requests) {
            $requests++;
            if (in_array($kind, ['timeout', 'network'], true)) {
                $cause = new ConnectException('SECRET-RAW-ERROR', new GuzzleRequest('POST', config('novapay.endpoint')), null, ['errno' => $kind === 'timeout' ? 28 : 7]);
                throw new ConnectionException('SECRET-RAW-ERROR', 0, $cause);
            }
            return match ($kind) {
                'http' => Http::response('SECRET-RAW-ERROR', 503),
                'denied' => Http::response('SECRET-RAW-ERROR', 401),
                'incomplete' => Http::response($this->soap('UserAuthenticationJWT', '<jwt>SECRET-JWT</jwt>')),
                'result' => Http::response($this->soap('UserAuthenticationJWT', '<message>SECRET-RAW-ERROR</message>', 'error')),
            };
        });
        foreach (range(1, 2) as $attempt) {
            $run = NovaPaySyncRun::create(['connection_id' => $connection->id, 'account_id' => $account->id, 'source' => 'balance']);
            (new SyncNovaPay($run->id))->handle(app(SyncService::class));
            $this->assertSame('failed', $run->fresh()->status);
            $this->assertSame($reason, $run->fresh()->error_code);
            $this->assertStringNotContainsString('SECRET', $run->fresh()->error_message);
            $this->assertStringNotContainsString('Згенеруйте', $run->fresh()->error_message);
        }
        $this->assertSame(1, $requests);
        $this->assertTrue($connection->fresh()->requires_auth);
        $this->assertSame($reason, $connection->fresh()->auth_error_code);
        $this->assertSame($run->fresh()->error_message, $connection->fresh()->auth_error_message);
        $this->assertSame($stored->refresh_token, DB::table('novapay_connections')->find($connection->id)->refresh_token);
        $this->assertSame($stored->public_certificate, DB::table('novapay_connections')->find($connection->id)->public_certificate);
        $this->assertSame(995000, NovaPayBalance::first()->amount_minor);
        $this->assertSame(0, app(SyncDispatcher::class)->refresh($account->fresh()));
        if ($kind === 'http') $this->assertStringContainsString('HTTP 503', $run->fresh()->error_message);
    }

    public function test_jwt_is_reused_until_expiry_margin_and_rotation_is_saved_before_balance_request(): void
    {
        $account = $this->account(); $authCalls = 0;
        Http::fake(function ($request) use (&$authCalls, $account) {
            if (str_contains($request->body(), 'UserAuthenticationJWT')) {
                $authCalls++;
                return Http::response($this->auth($this->jwt(7200)));
            }
            $this->assertSame('NEW-REFRESH', $account->connection->fresh()->refresh_token);
            $this->assertSame('NEW-CERT', $account->connection->fresh()->public_certificate);
            return Http::response($this->balance());
        });
        $client = app(NovaPayClient::class);
        $client->balance($account);
        $this->assertSame(now()->addSeconds(7140)->getTimestamp(), $account->connection->fresh()->token_expires_at->getTimestamp());
        $this->travel(30)->minutes(); $client->balance($account);
        $this->assertSame(1, $authCalls);
        $this->travel(89)->minutes(); $client->balance($account);
        $this->assertSame(2, $authCalls); Http::assertSentCount(5);
    }

    public function test_short_jwt_expiry_is_respected_instead_of_assuming_two_hours(): void
    {
        $account = $this->account();
        Http::fake(['*' => Http::sequence()->push($this->auth($this->jwt(180)))->push($this->balance())]);
        app(NovaPayClient::class)->balance($account);
        $this->assertSame(now()->addSeconds(120)->getTimestamp(), $account->connection->fresh()->token_expires_at->getTimestamp());
    }

    public function test_long_jwt_is_not_artificially_rotated_after_two_hours(): void
    {
        $account = $this->account(); $calls = 0;
        Http::fake(function ($request) use (&$calls) {
            if (str_contains($request->body(), 'UserAuthenticationJWT')) { $calls++; return Http::response($this->auth($this->jwt(43200))); }
            return Http::response($this->balance());
        });
        $client = app(NovaPayClient::class); $client->balance($account);
        $this->assertSame(now()->addSeconds(43140)->getTimestamp(), $account->connection->fresh()->token_expires_at->getTimestamp());
        $this->travel(6)->hours(); $client->balance($account);
        $this->assertSame(1, $calls); Http::assertSentCount(3);
    }

    public static function invalidExpiries(): array
    {
        return ['без exp' => [[]], 'текст' => [['exp' => 'tomorrow']], 'минуле' => [['exp' => 1]],
            'дробове' => [['exp' => 1900000000.25]], 'завелике' => [['exp' => PHP_INT_MAX]], 'масив' => [['exp' => []]]];
    }

    #[DataProvider('invalidExpiries')]
    public function test_invalid_expiry_preserves_rotated_pair_and_does_not_loop(array $payload): void
    {
        $account = $this->account();
        $jwt = 'header.'.rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=').'.signature';
        Http::fake(['*' => Http::response($this->auth($jwt))]);
        foreach (range(1, 2) as $attempt) {
            try { app(NovaPayClient::class)->balance($account); $this->fail('Некоректний строк має призупинити авторизацію.'); }
            catch (NovaPayException $e) { $this->assertSame('auth_invalid_expiry', $e->reason); }
        }
        $connection = $account->connection->fresh();
        $this->assertSame('NEW-REFRESH', $connection->refresh_token);
        $this->assertSame('NEW-CERT', $connection->public_certificate);
        $this->assertTrue($connection->requires_auth); $this->assertNull($connection->access_token);
        Http::assertSentCount(1);
    }

    public function test_database_failure_recovers_encrypted_pair_without_repeating_authentication(): void
    {
        $account = $this->account(); $failed = false;
        NovaPayConnection::saving(function ($connection) use (&$failed) {
            if (! $failed && $connection->refresh_token === 'NEW-REFRESH') { $failed = true; throw new \RuntimeException('SECRET-SQL'); }
        });
        Http::fake(['*' => Http::sequence()->push($this->auth($this->jwt(7200)))->push($this->balance())]);
        try { app(NovaPayClient::class)->balance($account); $this->fail('Очікувалася помилка запису БД.'); }
        catch (NovaPayException $e) {
            $this->assertSame('auth_persistence', $e->reason); $this->assertTrue($e->retryable);
            $this->assertStringNotContainsString('SECRET', $e->getMessage());
        }
        $this->assertSame('SECRET-REFRESH', $account->connection->fresh()->refresh_token);
        $path = config('novapay.auth_journal_directory').'/'.$account->connection_id.'.enc';
        $this->assertStringNotContainsString('NEW-REFRESH', file_get_contents($path));
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertSame('received', app(AuthTokenJournal::class)->read($account->connection_id)['phase']);
        app(NovaPayClient::class)->balance($account);
        $this->assertSame('NEW-REFRESH', $account->connection->fresh()->refresh_token);
        $this->assertFalse($account->connection->fresh()->requires_auth);
        $this->assertFileDoesNotExist($path); Http::assertSentCount(2);
    }

    public function test_jwt_expiring_during_database_write_retries_with_saved_pair_without_pausing(): void
    {
        $account = $this->account(); $delayed = false; $authCalls = 0;
        NovaPayConnection::saving(function ($connection) use (&$delayed) {
            if (! $delayed && $connection->refresh_token === 'NEW-REFRESH') { $delayed = true; $this->travel(3)->minutes(); }
        });
        Http::fake(function ($request) use (&$authCalls) {
            if (str_contains($request->body(), 'UserAuthenticationJWT')) {
                $authCalls++;
                if ($authCalls === 2) $this->assertStringContainsString('NEW-REFRESH', $request->body());
                return Http::response($this->auth($this->jwt(180)));
            }
            return Http::response($this->balance());
        });
        try { app(NovaPayClient::class)->balance($account); $this->fail('Прострочений під час запису JWT не можна надсилати.'); }
        catch (NovaPayException $e) { $this->assertSame('auth_expired_response', $e->reason); $this->assertTrue($e->retryable); }
        Http::assertSentCount(1);
        $this->assertFalse($account->connection->fresh()->requires_auth);
        $this->assertSame('NEW-REFRESH', $account->connection->fresh()->refresh_token);
        app(NovaPayClient::class)->balance($account);
        $this->assertSame(2, $authCalls); Http::assertSentCount(3);
    }

    public function test_recovery_after_jwt_expiry_rotates_known_new_pair_without_manual_authorization(): void
    {
        $account = $this->account(); $journal = app(AuthTokenJournal::class);
        $journal->write($account->connection_id, ['base' => $journal->fingerprint($account->connection), 'phase' => 'received',
            'received_at' => now()->subDay()->getTimestamp(), 'credentials' => [
                'refresh_token' => 'RECOVERED-REFRESH', 'public_certificate' => 'RECOVERED-CERT', 'access_token' => $this->jwt(-3600),
            ]]);
        Http::fake(function ($request) {
            if (str_contains($request->body(), 'UserAuthenticationJWT')) {
                $this->assertStringContainsString('RECOVERED-REFRESH', $request->body());
                $this->assertStringContainsString('RECOVERED-CERT', $request->body());
                $this->assertStringNotContainsString('SECRET-REFRESH', $request->body());
                return Http::response($this->auth($this->jwt(7200)));
            }
            return Http::response($this->balance());
        });
        app(NovaPayClient::class)->balance($account);
        $this->assertFalse($account->connection->fresh()->requires_auth);
        $this->assertSame('NEW-REFRESH', $account->connection->fresh()->refresh_token);
        $this->assertNull($journal->read($account->connection_id)); Http::assertSentCount(2);
    }

    public function test_interrupted_rotation_is_not_replayed_and_stale_journal_does_not_overwrite_owner_credentials(): void
    {
        $account = $this->account(); $journal = app(AuthTokenJournal::class);
        $journal->write($account->connection_id, ['base' => $journal->fingerprint($account->connection), 'phase' => 'pending']);
        try { app(NovaPayClient::class)->balance($account); $this->fail('Невизначену ротацію не можна повторювати.'); }
        catch (NovaPayException $e) { $this->assertSame('auth_uncertain', $e->reason); }
        Http::assertNothingSent();
        $account->connection->update(['requires_auth' => false, 'refresh_token' => 'OWNER-REFRESH', 'public_certificate' => 'OWNER-CERT',
            'access_token' => 'OWNER-JWT', 'token_expires_at' => now()->addHour(), 'auth_error_code' => null, 'auth_error_message' => null]);
        Http::fake(function ($request) {
            $this->assertStringNotContainsString('UserAuthenticationJWT', $request->body());
            $this->assertStringContainsString('OWNER-JWT', $request->body());
            return Http::response($this->balance());
        });
        app(NovaPayClient::class)->balance($account);
        $this->assertSame('OWNER-REFRESH', $account->connection->fresh()->refresh_token);
        $this->assertNull($journal->read($account->connection_id)); Http::assertSentCount(1);
    }

    public function test_unreadable_journal_or_unwritable_storage_blocks_authentication_without_exposing_secrets(): void
    {
        $account = $this->account(); $directory = config('novapay.auth_journal_directory');
        mkdir($directory, 0700, true); file_put_contents($directory.'/'.$account->connection_id.'.enc', 'SECRET-CORRUPT');
        try { app(NovaPayClient::class)->balance($account); $this->fail('Пошкоджений журнал має блокувати ротацію.'); }
        catch (NovaPayException $e) { $this->assertSame('auth_storage', $e->reason); $this->assertStringNotContainsString('SECRET', $e->getMessage()); }
        config(['novapay.auth_journal_directory' => $directory.'/'.$account->connection_id.'.enc/blocked']);
        try { app(NovaPayClient::class)->balance($account); $this->fail('Незаписуване сховище має блокувати ротацію.'); }
        catch (NovaPayException $e) { $this->assertSame('auth_storage', $e->reason); }
        $this->assertFalse($account->connection->fresh()->requires_auth); Http::assertNothingSent();
    }

    public function test_old_data_request_denial_does_not_pause_newly_saved_owner_credentials(): void
    {
        $account = $this->account();
        $account->connection->update(['access_token' => 'OLD-JWT', 'token_expires_at' => now()->addHour()]);
        Http::fake(function () use ($account) {
            $account->connection->fresh()->update(['refresh_token' => 'OWNER-REFRESH', 'public_certificate' => 'OWNER-CERT',
                'access_token' => 'OWNER-JWT', 'token_expires_at' => now()->addHour()]);
            return Http::response('', 401);
        });
        try { app(NovaPayClient::class)->balance($account); $this->fail('Очікувалася відмова старого запиту.'); }
        catch (NovaPayException $e) { $this->assertSame('authorization', $e->reason); }
        $this->assertFalse($account->connection->fresh()->requires_auth);
        $this->assertSame('OWNER-JWT', $account->connection->fresh()->access_token);
    }

    public function test_rate_limit_preserves_refresh_pair_and_existing_job_retry(): void
    {
        $account = $this->account();
        Http::fake(['*' => Http::response('SECRET-RAW-ERROR', 429)]);
        $run = NovaPaySyncRun::create(['connection_id' => $account->connection_id, 'account_id' => $account->id, 'source' => 'balance']);
        try { (new SyncNovaPay($run->id))->handle(app(SyncService::class)); $this->fail('Очікувалася повторна спроба.'); }
        catch (NovaPayException $e) { $this->assertSame('rate_limit', $e->reason); $this->assertTrue($e->retryable); }
        $this->assertSame('retrying', $run->fresh()->status);
        $this->assertFalse($account->connection->fresh()->requires_auth);
        $this->assertNull($account->connection->fresh()->auth_error_code);
        $this->assertSame('SECRET-REFRESH', $account->connection->fresh()->refresh_token);
    }

    public function test_explicitly_saved_connection_clears_diagnostic_without_http_during_save(): void
    {
        $account = $this->account();
        $account->connection->update(['requires_auth' => true, 'auth_error_code' => 'auth_timeout', 'auth_error_message' => 'Збій оновлення доступу.']);
        $this->actingAs(User::factory()->create(['role' => 'owner', 'is_active' => true]));
        $this->postJson('/finance/novapay/connections', ['connection_id' => $account->connection_id,
            'refresh_token' => 'USER-REFRESH', 'public_certificate' => 'USER-CERT'])->assertAccepted();
        $connection = $account->connection->fresh();
        $this->assertFalse($connection->requires_auth);
        $this->assertNull($connection->auth_error_code); $this->assertNull($connection->auth_error_message);
        Http::assertNothingSent();
    }

    public function test_previously_paused_connection_stays_paused_without_touching_tokens_or_calling_provider(): void
    {
        $account = $this->account(); $account->connection->update(['requires_auth' => true]);
        $before = DB::table('novapay_connections')->find($account->connection_id);
        try { app(NovaPayClient::class)->balance($account); $this->fail('Пауза має зберігатися.'); }
        catch (NovaPayException $e) { $this->assertSame('authorization', $e->reason); $this->assertStringNotContainsString('Згенеруйте', $e->getMessage()); }
        $after = DB::table('novapay_connections')->find($account->connection_id);
        $this->assertEquals($before, $after);
        Http::assertNothingSent();
    }

    public function test_legacy_generic_error_is_presented_as_unknown_without_rewriting_history_or_credentials(): void
    {
        $account = $this->account(); $account->connection->update(['requires_auth' => true]);
        $message = 'Авторизацію NovaPay не підтверджено. Згенеруйте нові параметри доступу в бізнес-кабінеті.';
        $run = NovaPaySyncRun::create(['connection_id' => $account->connection_id, 'account_id' => $account->id,
            'source' => 'balance', 'status' => 'failed', 'error_code' => 'authorization', 'error_message' => $message]);
        $before = DB::table('novapay_connections')->find($account->connection_id);
        $this->actingAs(User::factory()->create(['role' => 'owner', 'is_active' => true]));
        $response = $this->getJson('/finance/novapay/data')->assertOk()->assertDontSee('Згенеруйте');
        $this->assertStringContainsString('Точну причину не збережено', $response->json('sync.0.error_message'));
        $this->assertSame($message, $run->fresh()->error_message);
        $this->assertEquals($before, DB::table('novapay_connections')->find($account->connection_id));
        Http::assertNothingSent();
    }
}
