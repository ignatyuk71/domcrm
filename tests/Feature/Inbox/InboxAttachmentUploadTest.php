<?php

namespace Tests\Feature\Inbox;

use App\Models\InboxContact;
use App\Models\InboxConversation;
use App\Models\MetaConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Безпека завантаження вкладень inbox: не можна залити виконуваний файл
 * (shell.php) у webroot → RCE. Дозволені лише фото/відео/pdf.
 */
class InboxAttachmentUploadTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        return User::factory()->create(['role' => User::ROLE_OPERATOR, 'is_active' => true]);
    }

    private function conversation(): InboxConversation
    {
        $conn = MetaConnection::create([
            'page_id' => 'PAGE1', 'page_name' => 'Test Page', 'page_access_token' => 'tok', 'status' => 'active',
        ]);
        $contact = InboxContact::create([
            'meta_connection_id' => $conn->id, 'channel' => 'facebook', 'external_id' => 'USER1', 'name' => 'Іван',
        ]);

        return InboxConversation::create([
            'meta_connection_id' => $conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook',
            'last_message_at' => now(), 'last_message_direction' => 'in',
        ]);
    }

    public function test_rejects_php_upload(): void
    {
        $conv = $this->conversation();
        $php = UploadedFile::fake()->create('shell.php', 50, 'application/x-httpd-php');

        $this->actingAs($this->operator())
            ->postJson("/api/inbox/conversations/{$conv->id}/send-attachment", ['file' => $php])
            ->assertStatus(422);

        // Файл не має зʼявитись у webroot.
        $this->assertFalse(file_exists(public_path('inbox-uploads/shell.php')));
    }

    public function test_rejects_svg_upload(): void
    {
        $conv = $this->conversation();
        $svg = UploadedFile::fake()->create('x.svg', 10, 'image/svg+xml'); // SVG може нести XSS — не дозволяємо

        $this->actingAs($this->operator())
            ->postJson("/api/inbox/conversations/{$conv->id}/send-attachment", ['file' => $svg])
            ->assertStatus(422);
    }
}
