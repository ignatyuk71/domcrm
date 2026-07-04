<?php

namespace Tests\Feature\Inbox;

use App\Models\MetaConnection;
use App\Services\Meta\MetaSendService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaConnectionWatchdogTest extends TestCase
{
    use RefreshDatabase;

    private function connection(string $status = 'active'): MetaConnection
    {
        return MetaConnection::create([
            'page_id' => 'P_WD',
            'page_name' => 'Shop',
            'page_access_token' => 'tok',
            'status' => $status,
        ]);
    }

    private function graphError(int $code, string $message, int $http = 400): array
    {
        return ['error' => ['code' => $code, 'message' => $message, 'type' => 'OAuthException', 'http' => $http]];
    }

    public function test_check_command_keeps_live_connection_active(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'P_WD', 'name' => 'Shop'])]);
        $conn = $this->connection();

        $this->artisan('meta:check-connections')->assertExitCode(0);

        $this->assertSame('active', $conn->fresh()->status);
    }

    public function test_check_command_marks_dead_token(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response($this->graphError(190, 'Error validating access token'), 400)]);
        $conn = $this->connection();

        $this->artisan('meta:check-connections')->assertExitCode(0);

        $conn->refresh();
        $this->assertSame('error', $conn->status);
        $this->assertStringContainsString('Error validating access token', $conn->last_error);
    }

    public function test_check_command_ignores_transient_failures(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response($this->graphError(4, 'Application request limit reached'), 400)]);
        $conn = $this->connection();

        $this->artisan('meta:check-connections')->assertExitCode(0);

        $this->assertSame('active', $conn->fresh()->status, 'rate limit не мусить класти підключення');
    }

    public function test_check_command_restores_recovered_connection(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'P_WD', 'name' => 'Shop'])]);
        $conn = $this->connection('error');
        $conn->update(['last_error' => 'було погано']);

        $this->artisan('meta:check-connections')->assertExitCode(0);

        $conn->refresh();
        $this->assertSame('active', $conn->status);
        $this->assertNull($conn->last_error);
    }

    public function test_send_failure_with_dead_token_marks_connection(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response($this->graphError(190, 'Session has expired'), 400)]);
        $conn = $this->connection();

        $result = app(MetaSendService::class)->sendText($conn, 'USER1', 'Привіт');

        $this->assertFalse($result['ok']);
        $conn->refresh();
        $this->assertSame('error', $conn->status);
        $this->assertStringContainsString('Session has expired', $conn->last_error);
    }

    public function test_send_failure_without_auth_error_keeps_status(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response($this->graphError(551, 'This person is not available'), 400)]);
        $conn = $this->connection();

        app(MetaSendService::class)->sendText($conn, 'USER1', 'Привіт');

        $this->assertSame('active', $conn->fresh()->status, 'звичайна відмова відправки — не привід класти підключення');
    }

    public function test_successful_send_self_heals_connection(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'm_1'])]);
        $conn = $this->connection('error');

        app(MetaSendService::class)->sendText($conn, 'USER1', 'Привіт');

        $conn->refresh();
        $this->assertSame('active', $conn->status);
        $this->assertNull($conn->last_error);
    }

    public function test_inbox_page_shows_banner_for_broken_connection(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'owner']);
        $conn = $this->connection('error');
        $conn->update(['last_error' => 'Токен недійсний: тест']);

        $this->actingAs($user)->get('/inbox')
            ->assertOk()
            ->assertSee('проблема з підключенням Meta', false)
            ->assertSee('Токен недійсний: тест');
    }

    public function test_inbox_page_has_no_banner_when_all_active(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'owner']);
        $this->connection();

        $this->actingAs($user)->get('/inbox')
            ->assertOk()
            ->assertDontSee('проблема з підключенням Meta', false);
    }
}