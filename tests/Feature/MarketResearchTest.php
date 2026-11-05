<?php

namespace Tests\Feature;

use App\Models\MetaConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketResearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['meta_ad_library.access_token' => null, 'services.meta.graph_version' => 'v21.0']);
        Http::preventStrayRequests();
    }

    private function connection(string $status = 'active'): MetaConnection
    {
        return MetaConnection::create([
            'page_id' => '123456',
            'page_name' => 'Тестова крамниця',
            'page_access_token' => 'secret-page-token',
            'status' => $status,
        ]);
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner']);
    }

    private function check(?MetaConnection $connection = null, array $extra = [])
    {
        return $this->postJson('/api/market-research/check', array_merge([
            'connection_id' => $connection?->id, 'search' => 'pajama', 'country' => 'PL',
        ], $extra));
    }

    public function test_page_requires_owner_and_does_not_send_requests_on_load(): void
    {
        $this->get('/market-research')->assertRedirect('/login');
        $this->check()->assertUnauthorized();
        foreach (['operator', 'packer'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get('/market-research')->assertForbidden();
            $this->check()->assertForbidden();
        }

        $this->connection();
        $this->actingAs($this->owner())->get('/market-research')->assertOk()
            ->assertSee('crm-market-research')->assertSee('Аналіз ринку')
            ->assertDontSee('secret-page-token');
        Http::assertNothingSent();
    }

    public function test_missing_credentials_are_reported_without_outbound_requests(): void
    {
        $this->actingAs($this->owner());
        $this->check()->assertOk()->assertJsonPath('page.state', 'missing')
            ->assertJsonPath('library.state', 'missing')->assertJsonPath('library.token_source', 'none');
        Http::assertNothingSent();
    }

    public function test_page_and_ad_library_are_verified_independently_without_changing_chat(): void
    {
        $connection = $this->connection();
        config(['meta_ad_library.access_token' => 'secret-library-token']);
        Http::fake([
            'graph.facebook.com/v21.0/123456*' => Http::response(['id' => '123456', 'name' => 'Крамниця']),
            'graph.facebook.com/v21.0/ads_archive*' => Http::response([
                'data' => [[
                    'id' => '987654', 'page_name' => 'Конкурент', 'page_id' => '555',
                    'ad_creative_bodies' => ['Нова колекція піжам'],
                    'ad_creative_link_titles' => ['Піжами'], 'publisher_platforms' => ['instagram'],
                    'ad_snapshot_url' => 'https://www.facebook.com/?access_token=secret-library-token',
                ]],
                'paging' => ['next' => 'https://graph.facebook.com/?access_token=secret-library-token'],
            ]),
        ]);

        $this->actingAs($this->owner());
        $response = $this->check($connection)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('page.state', 'connected')->assertJsonPath('page.name', 'Крамниця')
            ->assertJsonPath('library.state', 'connected')->assertJsonPath('library.token_source', 'user')
            ->assertJsonPath('library.ads.0.body', 'Нова колекція піжам')
            ->assertJsonPath('library.ads.0.url', 'https://www.facebook.com/ads/library/?id=987654');
        $this->assertStringNotContainsString('secret-library-token', $response->getContent());
        $this->assertStringNotContainsString('secret-page-token', $response->getContent());
        $this->assertStringNotContainsString('paging', $response->getContent());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/ads_archive')
            && $request->hasHeader('Authorization', 'Bearer secret-library-token')
            && $request['ad_type'] === 'ALL' && $request['ad_active_status'] === 'ACTIVE'
            && $request['ad_reached_countries'] === '["PL"]' && ! str_contains($request->url(), 'secret-library-token'));
        $this->assertSame('active', $connection->fresh()->status);
        $this->assertSame('secret-page-token', $connection->fresh()->page_access_token);
    }

    public function test_denied_library_access_preserves_page_status_and_redacts_meta_error(): void
    {
        $connection = $this->connection();
        Http::fake([
            'graph.facebook.com/v21.0/123456*' => Http::response(['id' => '123456', 'name' => 'Крамниця']),
            'graph.facebook.com/v21.0/ads_archive*' => Http::response(['error' => [
                'code' => 10, 'error_subcode' => 2332002,
                'message' => 'Register for Ad Library. secret-page-token https://example.com?access_token=other-secret',
            ]], 400),
        ]);

        $this->actingAs($this->owner());
        $response = $this->check($connection)->assertOk()->assertJsonPath('page.state', 'connected')
            ->assertJsonPath('library.state', 'error')->assertJsonPath('library.token_source', 'page')
            ->assertJsonPath('library.error_code', 10)->assertJsonPath('library.error_subcode', 2332002)
            ->assertJsonCount(0, 'library.ads');
        $this->assertStringNotContainsString('secret-page-token', $response->getContent());
        $this->assertStringNotContainsString('other-secret', $response->getContent());
        $this->assertStringContainsString('Register for Ad Library', $response->getContent());
        $this->assertSame('active', $connection->fresh()->status);
        $this->assertNull($connection->fresh()->last_error);
    }

    public function test_empty_search_result_confirms_api_access_even_without_a_facebook_page(): void
    {
        config(['meta_ad_library.access_token' => 'secret-library-token']);
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);
        $this->actingAs($this->owner());
        $this->check()->assertOk()->assertJsonPath('page.state', 'missing')
            ->assertJsonPath('library.state', 'connected')->assertJsonCount(0, 'library.ads');
        Http::assertSentCount(1);
    }

    public function test_network_failure_does_not_leak_exceptions_or_change_connection(): void
    {
        $connection = $this->connection();
        Http::fake(fn () => throw new ConnectionException('secret exception with secret-page-token'));
        $this->actingAs($this->owner());
        $response = $this->check($connection)->assertOk()->assertJsonPath('page.state', 'unavailable')
            ->assertJsonPath('library.state', 'unavailable');
        $this->assertStringNotContainsString('secret', $response->getContent());
        $this->assertSame('active', $connection->fresh()->status);
    }

    public function test_expired_token_is_not_reported_as_a_live_connection(): void
    {
        $connection = $this->connection();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 190]], 400)]);
        $this->actingAs($this->owner());
        $this->check($connection)->assertOk()->assertJsonPath('page.state', 'invalid_token')
            ->assertJsonPath('library.state', 'invalid_token');
        $this->assertSame('active', $connection->fresh()->status);
    }

    public function test_ukrainian_commercial_ads_offer_web_search_without_misleading_api_results(): void
    {
        $this->actingAs($this->owner());
        $this->check(null, ['country' => 'UA'])->assertOk()->assertJsonPath('library.state', 'web_only');
        Http::assertNothingSent();
    }

    public function test_validation_rejects_bad_queries_and_disconnected_pages_before_meta_request(): void
    {
        $connection = $this->connection('disconnected');
        $this->actingAs($this->owner());
        $this->check($connection, ['country' => 'XX', 'search' => '   '])->assertUnprocessable()
            ->assertJsonValidationErrors(['connection_id', 'country', 'search']);
        $this->check(null, ['search' => ['unexpected']])->assertUnprocessable()->assertJsonValidationErrors('search');
        Http::assertNothingSent();
    }

    public function test_meta_rate_limit_is_temporary_and_malformed_success_is_not_accepted(): void
    {
        config(['meta_ad_library.access_token' => 'secret-library-token']);
        Http::fakeSequence()->push(['error' => ['code' => 4]], 429)->push(['unexpected' => true]);
        $this->actingAs($this->owner());
        $this->check()->assertOk()->assertJsonPath('library.state', 'unavailable');
        $this->check()->assertOk()->assertJsonPath('library.state', 'error');
    }

    public function test_repeated_checks_are_rate_limited(): void
    {
        $this->actingAs($this->owner());
        for ($i = 0; $i < 6; $i++) {
            $this->check()->assertOk();
        }
        $this->check()->assertTooManyRequests();
        Http::assertNothingSent();
    }
}
