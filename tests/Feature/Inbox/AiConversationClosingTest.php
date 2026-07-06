<?php

namespace Tests\Feature\Inbox;

use App\Models\InboxContact;
use App\Models\InboxConversation;
use App\Models\MetaConnection;
use App\Services\Ai\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Сторож правила «вмій завершити розмову» (кейс Ольги Таравської 06.07:
 * клієнтка тричі прощалась — «щиро дякую», «❤️», листівка — а бот щоразу
 * знову пропонував замовити й перепитував «які цікавлять?»).
 */
class AiConversationClosingTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_has_closing_rule_without_re_pitch(): void
    {
        $conn = MetaConnection::create(['page_id' => 'P_CL', 'page_name' => 'Shop', 'page_access_token' => 'tok', 'status' => 'active']);
        $contact = InboxContact::create(['meta_connection_id' => $conn->id, 'channel' => 'instagram', 'external_id' => 'U_CL']);
        $conv = InboxConversation::create(['meta_connection_id' => $conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'instagram']);

        $system = collect(app(PromptBuilder::class)->buildSystemPrompt($conv))
            ->pluck('text')->implode("\n");

        $this->assertStringContainsString('ЗАВЕРШЕННЯ РОЗМОВИ (ОБОВ\'ЯЗКОВЕ ПРАВИЛО', $system);
        // Після прощання — заборона заклику й перепитування.
        $this->assertStringContainsString('ЗАБОРОНЕНО: заклик до покупки, питання «які саме вас цікавлять?»', $system);
        $this->assertStringContainsString('Одне тепле речення — і крапка', $system);
        // Приклад із самого кейсу — сердечко без ре-пітчу.
        $this->assertStringContainsString('Дякую вам, гарного дня', $system);
    }
}
