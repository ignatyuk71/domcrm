<?php

namespace Tests\Feature;

use App\Models\MetaConnection;
use App\Services\MetaConnectionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MetaConnectionServiceTest extends TestCase
{
    public function test_sync_webhook_subscription_marks_connection_as_subscribed(): void
    {
        Http::fake([
            'https://graph.facebook.com/*/subscribed_apps' => Http::response([
                'success' => true,
            ], 200),
        ]);

        $connection = MetaConnection::query()->create([
            'provider' => 'meta',
            'name' => 'Dream v doma',
            'facebook_page_id' => '103823131052820',
            'access_token' => 'page-token',
            'verify_token' => 'verify-token',
            'webhook_secret' => 'webhook-secret',
            'is_active' => true,
            'webhook_subscribed' => false,
        ]);

        $service = app(MetaConnectionService::class);
        $fresh = $service->syncWebhookSubscription($connection);

        $this->assertTrue($fresh->webhook_subscribed);
        $this->assertSame(
            ['messages', 'message_deliveries', 'message_reads', 'messaging_postbacks', 'messaging_optins', 'feed', 'comments'],
            $fresh->webhook_fields
        );

        Http::assertSent(function ($request) {
            return str_contains((string) $request->url(), '/103823131052820/subscribed_apps')
                && $request['subscribed_fields'] === 'messages,message_deliveries,message_reads,messaging_postbacks,messaging_optins,feed,comments';
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('meta_connections')) {
            Schema::create('meta_connections', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->string('provider', 32)->default('meta');
                $table->string('facebook_page_id')->nullable();
                $table->longText('access_token')->nullable();
                $table->string('verify_token')->nullable();
                $table->string('webhook_secret')->nullable();
                $table->boolean('webhook_subscribed')->default(false);
                $table->json('webhook_fields')->nullable();
                $table->boolean('is_active')->default(true);
                $table->text('last_error')->nullable();
                $table->timestamps();
            });
        }
    }
}
