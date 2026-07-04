<?php

namespace Tests\Feature\Inbox;

use App\Jobs\PersistInboxAttachments;
use App\Models\InboxContact;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\MetaConnection;
use App\Services\Meta\InboxMediaStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InboxMediaStoreTest extends TestCase
{
    use RefreshDatabase;

    /** Створені під час тесту файли — приберемо за собою. */
    private array $createdFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $rel) {
            @unlink(public_path($rel));
        }
        parent::tearDown();
    }

    private function makeMessage(array $attachments): InboxMessage
    {
        $conn = MetaConnection::create(['page_id' => 'P_M', 'page_name' => 'Shop', 'page_access_token' => 'tok', 'status' => 'active']);
        $contact = InboxContact::create(['meta_connection_id' => $conn->id, 'channel' => 'facebook', 'external_id' => 'U_M']);
        $conv = InboxConversation::create(['meta_connection_id' => $conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook']);

        return InboxMessage::create([
            'inbox_conversation_id' => $conv->id,
            'direction' => 'in',
            'sender' => 'contact',
            'external_message_id' => 'mid_' . uniqid(),
            'attachments' => $attachments,
            'sent_at' => now(),
        ]);
    }

    private function rememberLocal(InboxMessage $msg): void
    {
        foreach ($msg->fresh()->attachments ?? [] as $a) {
            if (!empty($a['local'])) {
                $this->createdFiles[] = $a['local'];
            }
        }
    }

    public function test_image_attachment_is_downloaded_and_original_url_kept(): void
    {
        Http::fake(['cdn.example/*' => Http::response('fake-jpeg-bytes', 200, ['Content-Type' => 'image/jpeg'])]);

        $msg = $this->makeMessage([['type' => 'image', 'url' => 'https://cdn.example/photo.jpg']]);
        (new PersistInboxAttachments($msg->id))->handle(new InboxMediaStore());
        $this->rememberLocal($msg);

        $att = $msg->fresh()->attachments[0];
        $this->assertSame('https://cdn.example/photo.jpg', $att['url'], 'оригінальний url мусить лишитись');
        $this->assertNotEmpty($att['local']);
        $this->assertStringStartsWith('inbox-media/', $att['local']);
        $this->assertStringEndsWith('.jpg', $att['local']);
        $this->assertFileExists(public_path($att['local']));
    }

    public function test_dead_link_marked_dead_and_not_retried(): void
    {
        Http::fake(['cdn.example/*' => Http::response('gone', 404)]);

        $msg = $this->makeMessage([['type' => 'image', 'url' => 'https://cdn.example/expired.jpg']]);
        (new PersistInboxAttachments($msg->id))->handle(new InboxMediaStore());

        $att = $msg->fresh()->attachments[0];
        $this->assertTrue($att['dead']);
        $this->assertSame('https://cdn.example/expired.jpg', $att['url']);
        $this->assertArrayNotHasKey('local', $att);

        // Повторний прохід не робить нових HTTP-запитів (dead пропускається).
        (new PersistInboxAttachments($msg->id))->handle(new InboxMediaStore());
        Http::assertSentCount(1);
    }

    public function test_unsafe_content_type_is_not_stored_locally(): void
    {
        Http::fake(['cdn.example/*' => Http::response('<svg onload=alert(1)>', 200, ['Content-Type' => 'image/svg+xml'])]);

        $msg = $this->makeMessage([['type' => 'file', 'url' => 'https://cdn.example/evil.svg']]);
        (new PersistInboxAttachments($msg->id))->handle(new InboxMediaStore());

        $att = $msg->fresh()->attachments[0];
        $this->assertArrayNotHasKey('local', $att, 'svg не можна віддавати з нашого домену');
        $this->assertTrue($att['dead']);
    }

    public function test_server_error_leaves_attachment_for_retry(): void
    {
        Http::fake(['cdn.example/*' => Http::response('oops', 500)]);

        $msg = $this->makeMessage([['type' => 'image', 'url' => 'https://cdn.example/photo.jpg']]);
        (new PersistInboxAttachments($msg->id))->handle(new InboxMediaStore());

        $att = $msg->fresh()->attachments[0];
        $this->assertArrayNotHasKey('local', $att);
        $this->assertArrayNotHasKey('dead', $att, '5xx — тимчасово, крон мусить доспробувати');
    }

    public function test_backfill_command_persists_old_messages(): void
    {
        Http::fake(['cdn.example/*' => Http::response('bytes', 200, ['Content-Type' => 'image/png'])]);

        $msg = $this->makeMessage([['type' => 'image', 'url' => 'https://cdn.example/old.png']]);
        $msg->update(['created_at' => now()->subDays(30)]);

        $this->artisan('inbox:persist-media --days=0 --limit=10')
            ->expectsOutputToContain('докачано файлів: 1')
            ->assertExitCode(0);
        $this->rememberLocal($msg);

        $this->assertNotEmpty($msg->fresh()->attachments[0]['local']);
    }

    public function test_own_uploads_are_not_downloaded(): void
    {
        Http::fake();

        $own = rtrim((string) config('app.url'), '/') . '/inbox-uploads/x.jpg';
        $msg = $this->makeMessage([['type' => 'image', 'url' => $own]]);
        (new PersistInboxAttachments($msg->id))->handle(new InboxMediaStore());

        $this->assertArrayNotHasKey('local', $msg->fresh()->attachments[0]);
        Http::assertNothingSent();
    }

    public function test_messages_endpoint_prefers_local_copy(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'owner']);
        $msg = $this->makeMessage([['type' => 'image', 'url' => 'https://cdn.example/a.jpg', 'local' => 'inbox-media/local-copy.jpg']]);

        $resp = $this->actingAs($user)->getJson('/api/inbox/conversations/' . $msg->inbox_conversation_id . '/messages');

        $resp->assertOk();
        $this->assertSame(url('inbox-media/local-copy.jpg'), $resp->json('messages.0.attachments.0.url'));
    }
}