<?php

namespace Tests\Feature\Inbox;

use App\Models\InboxContact;
use App\Models\InboxConversation;
use App\Models\MetaConnection;
use App\Services\Ai\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Сторож правила «розмір завжди з сантиметрами»: см беруться ЛИШЕ з
 * розмірної сітки лінії (блок «Від магазину»), без сітки — без вигадок.
 */
class AiSizeChartTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_requires_cm_from_line_size_chart(): void
    {
        $conn = MetaConnection::create(['page_id' => 'P_SC', 'page_name' => 'Shop', 'page_access_token' => 'tok', 'status' => 'active']);
        $contact = InboxContact::create(['meta_connection_id' => $conn->id, 'channel' => 'instagram', 'external_id' => 'U_SC']);
        $conv = InboxConversation::create(['meta_connection_id' => $conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'instagram']);

        $system = collect(app(PromptBuilder::class)->buildSystemPrompt($conv))
            ->pluck('text')->implode("\n");

        $this->assertStringContainsString('РОЗМІРИ — ЗАВЖДИ З САНТИМЕТРАМИ (ОБОВ\'ЯЗКОВЕ ПРАВИЛО)', $system);
        $this->assertStringContainsString('38/39 (25–25,5 см)', $system);
        $this->assertStringContainsString('Сантиметри бери ВИКЛЮЧНО з сітки в каталозі', $system);
        $this->assertStringContainsString('цифру НЕ вигадуй', $system);
    }
}