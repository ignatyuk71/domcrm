<?php

namespace Tests\Feature\Inbox;

use App\Models\InboxContact;
use App\Models\InboxConversation;
use App\Models\MetaConnection;
use App\Services\Ai\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Сторож правила «наявність — тільки по каталогу» (кейс Begun 08.07: бот
 * мав у каталозі коричневі 38/39=50, але сказав клієнту «38-го немає» →
 * зламав готового покупця). Правило заборони вигаданої відсутності.
 */
class AiStockHonestyTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_forbids_inventing_out_of_stock(): void
    {
        $conn = MetaConnection::create(['page_id' => 'P_SH', 'page_name' => 'Shop', 'page_access_token' => 'tok', 'status' => 'active']);
        $contact = InboxContact::create(['meta_connection_id' => $conn->id, 'channel' => 'facebook', 'external_id' => 'U_SH']);
        $conv = InboxConversation::create(['meta_connection_id' => $conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook']);

        $system = collect(app(PromptBuilder::class)->buildSystemPrompt($conv))
            ->pluck('text')->implode("\n");

        $this->assertStringContainsString('НАЯВНІСТЬ РОЗМІРУ/КОЛЬОРУ = ТІЛЬКИ ПО КАТАЛОГУ', $system);
        $this->assertStringContainsString('НІКОЛИ не вигадуй, що чогось немає', $system);
        // Приклад саме того кейсу — щоб правило лишалось предметним.
        $this->assertStringContainsString('38-го в коричневому немає', $system);
    }
}
