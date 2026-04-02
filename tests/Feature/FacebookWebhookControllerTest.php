<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\MetaConnection;
use App\Services\MetaService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Mockery;
use Tests\TestCase;

class FacebookWebhookControllerTest extends TestCase
{
    public function test_feed_change_comment_is_saved_as_inbound_chat_message(): void
    {
        MetaConnection::query()->create([
            'provider' => 'meta',
            'name' => 'Dream v doma',
            'facebook_page_id' => '103823131052820',
            'access_token' => 'page-token',
            'verify_token' => 'verify-token',
            'webhook_secret' => 'webhook-secret',
            'is_active' => true,
        ]);

        $metaService = Mockery::mock(MetaService::class);
        $metaService->shouldReceive('getContactProfile')
            ->once()
            ->with('user-100', 'messenger')
            ->andReturn([
                'name' => 'Ирина Шестакова',
            ]);
        $this->app->instance(MetaService::class, $metaService);

        $payload = [
            'object' => 'page',
            'entry' => [[
                'id' => '103823131052820',
                'changes' => [[
                    'field' => 'feed',
                    'value' => [
                        'from' => [
                            'id' => 'user-100',
                            'name' => 'Ирина Шестакова',
                        ],
                        'message' => 'яка ціна',
                        'item' => 'comment',
                        'verb' => 'add',
                        'comment_id' => 'comment-100',
                        'post_id' => 'post-100',
                        'created_time' => '2026-03-27T12:15:31+0000',
                    ],
                ]],
            ]],
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = 'sha256=' . hash_hmac('sha256', $json, 'webhook-secret');

        $response = $this->call(
            'POST',
            '/api/fb-webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            $json
        );

        $response->assertOk();

        $message = ChatMessage::query()->first();
        $this->assertNotNull($message);
        $this->assertSame('change:feed:comment-100', $message->external_message_id);
        $this->assertSame('inbound', $message->direction);
        $this->assertSame('webhook', $message->source);
        $this->assertSame('яка ціна', $message->text);
        $this->assertSame('feed', data_get($message->meta, 'webhook_field'));
        $this->assertSame('comment', data_get($message->meta, 'webhook_item'));

        $conversation = ChatConversation::query()->with('contact')->first();
        $this->assertNotNull($conversation);
        $this->assertSame('messenger', $conversation->contact->platform);
        $this->assertSame('comment', $conversation->thread_kind);
        $this->assertSame('Ирина Шестакова', $conversation->contact->display_name);
        $this->assertSame($message->id, $conversation->last_message_id);

        $connection = MetaConnection::query()->first();
        $this->assertSame('messenger', $connection?->last_webhook_platform);
        $this->assertNotNull($connection?->last_webhook_at);
    }

    public function test_instagram_direct_message_creates_direct_conversation(): void
    {
        MetaConnection::query()->create([
            'provider' => 'meta',
            'name' => 'Dream v doma',
            'facebook_page_id' => '103823131052820',
            'access_token' => 'page-token',
            'verify_token' => 'verify-token',
            'webhook_secret' => 'webhook-secret',
            'is_active' => true,
        ]);

        $metaService = Mockery::mock(MetaService::class);
        $metaService->shouldReceive('getContactProfile')
            ->times(3)
            ->with('ig-user-100', 'instagram')
            ->andReturn([
                'name' => 'test instagram',
                'username' => 'test.instagram',
            ]);
        $metaService->shouldReceive('updateCustomerProfile')
            ->once()
            ->with(Mockery::type(\App\Models\Customer::class), 'instagram');
        $this->app->instance(MetaService::class, $metaService);

        $payload = [
            'object' => 'instagram',
            'entry' => [[
                'id' => '17841425541648437',
                'messaging' => [[
                    'sender' => ['id' => 'ig-user-100'],
                    'recipient' => ['id' => '17841425541648437'],
                    'timestamp' => 1775120000000,
                    'message' => [
                        'mid' => 'ig-mid-100',
                        'text' => 'Привіт, цікавить модель',
                    ],
                ]],
            ]],
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = 'sha256=' . hash_hmac('sha256', $json, 'webhook-secret');

        $response = $this->call(
            'POST',
            '/api/fb-webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            $json
        );

        $response->assertOk();

        $conversation = ChatConversation::query()->with('contact')->first();
        $this->assertNotNull($conversation);
        $this->assertSame('instagram', $conversation->contact->platform);
        $this->assertSame('direct', $conversation->thread_kind);

        $connection = MetaConnection::query()->first();
        $this->assertSame('instagram', $connection?->last_webhook_platform);
        $this->assertNotNull($connection?->last_webhook_at);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.meta.app_secret', '');
        Bus::fake();
        $this->createMinimalChatSchema();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function createMinimalChatSchema(): void
    {
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
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('last_webhook_at')->nullable();
            $table->string('last_webhook_platform', 32)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            });
        }

        if (!Schema::hasTable('customers')) {
            Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('note')->nullable();
            $table->string('fb_user_id')->nullable();
            $table->string('fb_profile_pic')->nullable();
            $table->string('instagram_user_id')->nullable();
            $table->string('instagram_username')->nullable();
            $table->timestamps();
            });
        }

        if (!Schema::hasTable('chat_stages')) {
            Schema::create('chat_stages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->string('color', 20)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_final')->default(false);
            $table->timestamps();
            });
        }

        if (!Schema::hasTable('chat_contacts')) {
            Schema::create('chat_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('meta_connection_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('platform', 32);
            $table->string('external_user_id', 191);
            $table->string('external_username', 191)->nullable();
            $table->string('display_name', 191)->nullable();
            $table->string('first_name', 120)->nullable();
            $table->string('last_name', 120)->nullable();
            $table->string('avatar_path')->nullable();
            $table->text('avatar_original_url')->nullable();
            $table->json('profile_payload')->nullable();
            $table->timestamp('last_profile_sync_at')->nullable();
            $table->timestamps();
            });
        }

        if (!Schema::hasTable('chat_conversations')) {
            Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('meta_connection_id');
            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('stage_id');
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->string('status', 32)->default('open');
            $table->string('thread_kind', 32)->default('direct');
            $table->string('external_thread_id', 191)->nullable();
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->text('last_message_preview')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_outbound_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('snooze_until')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            });
        }

        if (!Schema::hasTable('chat_messages')) {
            Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('parent_message_id')->nullable();
            $table->string('external_message_id', 191)->nullable();
            $table->string('external_parent_message_id', 191)->nullable();
            $table->string('direction', 32);
            $table->string('message_type', 32)->default('text');
            $table->string('delivery_status', 32)->default('pending');
            $table->string('source', 32)->default('webhook');
            $table->longText('text')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            });
        }

        if (!Schema::hasTable('chat_message_attachments')) {
            Schema::create('chat_message_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('message_id');
            $table->string('attachment_type', 32);
            $table->string('mime_type')->nullable();
            $table->string('storage_disk', 32)->nullable();
            $table->string('path')->nullable();
            $table->string('url')->nullable();
            $table->string('original_url')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
            });
        }

        if (\DB::table('chat_stages')->count() === 0) {
            \DB::table('chat_stages')->insert([
                [
                    'code' => 'no_stage',
                    'name' => 'Без етапу',
                    'color' => '#94A3B8',
                    'sort_order' => 0,
                    'is_default' => true,
                    'is_final' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'code' => 'new',
                    'name' => 'Новий',
                    'color' => '#3B82F6',
                    'sort_order' => 10,
                    'is_default' => false,
                    'is_final' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'code' => 'waiting_reply',
                    'name' => 'Чекаємо відповідь',
                    'color' => '#F59E0B',
                    'sort_order' => 20,
                    'is_default' => false,
                    'is_final' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }
    }
}
