<?php

namespace Tests\Feature;

use App\Models\ChatContact;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\MetaConnection;
use App\Services\ChatAiOrchestratorService;
use App\Services\MetaService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class SyncRecentMetaConversationsTest extends TestCase
{
    public function test_sync_recent_conversations_creates_missing_messenger_chat(): void
    {
        Http::fake([
            'https://graph.facebook.com/*/me/conversations*' => Http::response([
                'data' => [[
                    'id' => 'thread-100',
                    'participants' => [
                        'data' => [
                            ['id' => '103823131052820', 'name' => 'Dream v doma'],
                            ['id' => 'user-100', 'name' => 'Анастасия Либохоря'],
                        ],
                    ],
                ]],
            ], 200),
            'https://graph.facebook.com/*/thread-100/messages*' => Http::response([
                'data' => [[
                    'id' => 'mid-100',
                    'message' => 'Можна замовити тапулі)',
                    'created_time' => '2026-03-27T18:33:00+0000',
                    'from' => ['id' => 'user-100'],
                ]],
            ], 200),
        ]);

        MetaConnection::query()->create([
            'provider' => 'meta',
            'name' => 'Dream v doma',
            'facebook_page_id' => '103823131052820',
            'access_token' => 'page-token',
            'verify_token' => 'verify-token',
            'webhook_secret' => 'webhook-secret',
            'is_active' => true,
        ]);

        $added = app(MetaService::class)->syncRecentConversations('messenger', 10, 10);

        $this->assertSame(1, $added);

        $contact = ChatContact::query()->first();
        $this->assertNotNull($contact);
        $this->assertSame('Анастасия Либохоря', $contact->display_name);
        $this->assertSame('user-100', $contact->external_user_id);

        $conversation = ChatConversation::query()->first();
        $this->assertNotNull($conversation);
        $this->assertSame('thread-100', $conversation->external_thread_id);
        $this->assertSame('direct', $conversation->thread_kind);

        $message = ChatMessage::query()->first();
        $this->assertNotNull($message);
        $this->assertSame('mid-100', $message->external_message_id);
        $this->assertSame('inbound', $message->direction);
        $this->assertSame('Можна замовити тапулі)', $message->text);
        $this->assertSame('sync', $message->source);

        $connection = MetaConnection::query()->first();
        $this->assertNotNull($connection?->last_sync_at);
    }

    public function test_sync_recent_conversations_triggers_ai_for_fresh_recovered_inbound_message(): void
    {
        $createdAt = now()->subMinutes(2)->utc()->format('Y-m-d\TH:i:sO');

        Http::fake([
            'https://graph.facebook.com/*/me/conversations*' => Http::response([
                'data' => [[
                    'id' => 'thread-200',
                    'participants' => [
                        'data' => [
                            ['id' => '103823131052820', 'name' => 'Dream v doma'],
                            ['id' => 'user-200', 'name' => 'Анастасия Либохоря'],
                        ],
                    ],
                ]],
            ], 200),
            'https://graph.facebook.com/*/thread-200/messages*' => Http::response([
                'data' => [[
                    'id' => 'mid-200',
                    'message' => 'Можна замовити тапулі)',
                    'created_time' => $createdAt,
                    'from' => ['id' => 'user-200'],
                ]],
            ], 200),
        ]);

        MetaConnection::query()->create([
            'provider' => 'meta',
            'name' => 'Dream v doma',
            'facebook_page_id' => '103823131052820',
            'access_token' => 'page-token',
            'verify_token' => 'verify-token',
            'webhook_secret' => 'webhook-secret',
            'is_active' => true,
        ]);

        $orchestrator = Mockery::mock(ChatAiOrchestratorService::class);
        $orchestrator->shouldReceive('handleRecoveredInboundMessageById')
            ->once()
            ->with(Mockery::type('int'));
        $this->app->instance(ChatAiOrchestratorService::class, $orchestrator);

        app(MetaService::class)->syncRecentConversations('messenger', 10, 10);

        $this->addToAssertionCount(1);
    }

    public function test_sync_recent_conversations_does_not_trigger_ai_for_old_recovered_message(): void
    {
        $createdAt = now()->subHours(2)->utc()->format('Y-m-d\TH:i:sO');

        Http::fake([
            'https://graph.facebook.com/*/me/conversations*' => Http::response([
                'data' => [[
                    'id' => 'thread-300',
                    'participants' => [
                        'data' => [
                            ['id' => '103823131052820', 'name' => 'Dream v doma'],
                            ['id' => 'user-300', 'name' => 'Іннеса Іванько'],
                        ],
                    ],
                ]],
            ], 200),
            'https://graph.facebook.com/*/thread-300/messages*' => Http::response([
                'data' => [[
                    'id' => 'mid-300',
                    'message' => 'Яка вартість?',
                    'created_time' => $createdAt,
                    'from' => ['id' => 'user-300'],
                ]],
            ], 200),
        ]);

        MetaConnection::query()->create([
            'provider' => 'meta',
            'name' => 'Dream v doma',
            'facebook_page_id' => '103823131052820',
            'access_token' => 'page-token',
            'verify_token' => 'verify-token',
            'webhook_secret' => 'webhook-secret',
            'is_active' => true,
        ]);

        $orchestrator = Mockery::mock(ChatAiOrchestratorService::class);
        $orchestrator->shouldNotReceive('handleRecoveredInboundMessageById');
        $this->app->instance(ChatAiOrchestratorService::class, $orchestrator);

        app(MetaService::class)->syncRecentConversations('messenger', 10, 10);

        $this->addToAssertionCount(1);
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
            ]);
        }
    }
}
