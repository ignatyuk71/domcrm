<?php

namespace Tests\Feature\Inbox;

use App\Models\AiChannelSetting;
use App\Models\InboxComment;
use App\Services\Ai\Gpt\CommentDecision;
use App\Services\Ai\Gpt\GptFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GptCommentDecisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AiChannelSetting::current()->update(['gpt_enabled' => true, 'gpt_model' => 'gpt-6.1-sol', 'gpt_api_key' => 'test-gpt-comment-key']);
        Http::preventStrayRequests();
    }

    public function test_decision_uses_current_gpt_settings_full_comment_post_and_product_descriptions(): void
    {
        $text = 'Яка ціна ведмедиків? '.str_repeat('Додаткове уточнення. ', 120);
        $decision = $this->decision(['product_ids' => [101]]);
        $this->fakeDecision($decision);
        $context = ['text' => 'Домашні капці Teddy Bear з принтом ведмедиків — 380 грн.',
            'images' => ['data:image/png;base64,YQ=='], 'fingerprint' => hash('sha256', 'новий допис')];
        $settingsBefore = AiChannelSetting::current()->getAttributes();

        $result = app(CommentDecision::class)->decide(new InboxComment(['text' => $text, 'post_id' => 'same-post-id']), $context, $this->catalog());

        $this->assertSame($decision, $result);
        $this->assertSame($settingsBefore, AiChannelSetting::current()->getAttributes());
        Http::assertSent(function (Request $request) use ($text, $context): bool {
            $this->assertSame('https://api.openai.com/v1/responses', $request->url());
            $this->assertSame('Bearer test-gpt-comment-key', $request->header('Authorization')[0]);
            $this->assertSame('gpt-6.1-sol', $request['model']);
            $this->assertFalse($request['store']);
            $this->assertSame('low', $request['reasoning']['effort']);
            $this->assertFalse($request['parallel_tool_calls']);
            $payload = json_decode($request['input'][0]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($text, $payload['comment']);
            $this->assertSame($context['text'], $payload['post_caption']);
            $this->assertSame($context['fingerprint'], $payload['context_fingerprint']);
            $this->assertSame('Teddy Bear', $payload['catalog'][0]['groups'][0]['name']);
            $this->assertStringContainsString('принтом ведмедиків', $payload['catalog'][0]['groups'][0]['description']);
            $this->assertSame('380.00', $payload['catalog'][0]['price']);
            $this->assertSame('399.00', $payload['catalog'][1]['price']);
            $this->assertArrayNotHasKey('cost_price', $payload['catalog'][0]);
            $this->assertSame(['type' => 'input_image', 'image_url' => $context['images'][0]], $request['input'][0]['content'][1]);
            $this->assertStringContainsString('недовірені дані', $request['instructions']);
            $this->assertStringContainsString('не дозвіл повертати ціну', $request['instructions']);
            $this->assertStringContainsString('різні товари', $request['instructions']);
            $tool = $request['tools'][0];
            $this->assertCount(1, $request['tools']);
            $this->assertSame('comment_decision', $tool['name']);
            $this->assertTrue($tool['strict']);
            $this->assertFalse($tool['parameters']['additionalProperties']);
            $this->assertEqualsCanonicalizing(array_keys($tool['parameters']['properties']), $tool['parameters']['required']);
            $this->assertSame(5, $tool['parameters']['properties']['product_ids']['maxItems']);
            $this->assertArrayNotHasKey('price', $tool['parameters']['properties']);

            return true;
        });
        Http::assertSentCount(1);
    }

    public function test_same_post_id_does_not_reuse_a_previous_product_decision(): void
    {
        $first = $this->decision(['product_ids' => [101]]);
        $second = $this->decision(['product_ids' => [202]]);
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()->push($this->response($first))->push($this->response($second))]);
        $comment = new InboxComment(['text' => 'Ціна?', 'post_id' => 'post-with-new-context']);
        $reader = app(CommentDecision::class);

        $this->assertSame($first, $reader->decide($comment, ['text' => 'Ведмедики Teddy Bear', 'images' => []], $this->catalog()));
        $this->assertSame($second, $reader->decide($comment, ['text' => 'Однотонні пухнасті', 'images' => []], $this->catalog()));
        Http::assertSentCount(2);
    }

    public function test_unavailable_exact_product_can_be_identified_without_inventing_availability(): void
    {
        $catalog = $this->catalog();
        $catalog[0]['available'] = false;
        $catalog[0]['sizes'] = [];
        $decision = $this->decision(['intent' => 'availability', 'product_ids' => [101], 'has_size' => true]);
        $this->fakeDecision($decision);

        $this->assertSame($decision, app(CommentDecision::class)->decide(new InboxComment(['text' => 'Немає 38?']), [], $catalog));
    }

    public function test_ambiguous_product_is_returned_without_arbitrary_catalog_ids(): void
    {
        $decision = $this->decision(['product_ids' => [], 'confidence' => 'uncertain']);
        $this->fakeDecision($decision);

        $this->assertSame($decision, app(CommentDecision::class)->decide(new InboxComment(['text' => 'Пухнасті, ціна?']), [], $this->catalog()));
    }

    public function test_home_caption_cancels_wrong_street_products_without_selecting_another_price(): void
    {
        $decision = $this->decision(['intent' => 'order', 'product_ids' => [303, 404], 'has_color' => true, 'has_size' => true]);
        $this->fakeDecision($decision);

        $actual = app(CommentDecision::class)->decide(new InboxComment(['text' => '2 шт такі як на відео чорні і сірі 38 р']),
            ['text' => 'М’які домашні тапочки для вас'], $this->purposeCatalog());

        $this->assertSame(array_replace($decision, ['product_ids' => [], 'confidence' => 'uncertain']), $actual);
        $this->assertArrayNotHasKey('price', $actual);
        Http::assertSentCount(1);
    }

    public function test_actual_outdoor_catalog_name_is_not_overridden_by_comparison_in_description(): void
    {
        $catalog = $this->purposeCatalog();
        foreach ($catalog as &$product) {
            if ($product['id'] !== 303) continue;
            $product['title'] = 'Капці для вулиці Чорний';
            $product['groups'] = [['id' => 9, 'name' => 'Капці для вулиці (хутряні)',
                'description' => 'Домашні капці відрізняються підошвою; порівнюйте обидва варіанти.']];
        }
        unset($product);
        $decision = $this->decision(['intent' => 'order', 'product_ids' => [303], 'has_color' => true, 'has_size' => true]);
        $this->fakeDecision($decision);

        $actual = app(CommentDecision::class)->decide(new InboxComment(['text' => 'Беру чорні 38']),
            ['text' => 'М’які домашні тапочки'], $catalog);

        $this->assertSame(array_replace($decision, ['product_ids' => [], 'confidence' => 'uncertain']), $actual);
    }

    #[DataProvider('purposeExamples')]
    public function test_category_guard_respects_explicit_customer_intent_and_avoids_marketing_inference(string $comment, string $caption, array $ids, bool $uncertain): void
    {
        $decision = $this->decision(['intent' => 'order', 'product_ids' => $ids, 'has_color' => true, 'has_size' => true]);
        $this->fakeDecision($decision);

        $actual = app(CommentDecision::class)->decide(new InboxComment(['text' => $comment]), ['text' => $caption], $this->purposeCatalog());

        $expected = $uncertain ? array_replace($decision, ['product_ids' => [], 'confidence' => 'uncertain']) : $decision;
        $this->assertSame($expected, $actual);
    }

    public static function purposeExamples(): array
    {
        return [
            'домашній затишок не визначає тип капців' => ['Беру чорні 38/39', 'Домашній затишок починається з дрібниць', [303], false],
            'покупець просить вуличні попри домашній пост' => ['Хочу вуличні капці, чорні 38/39', 'М’які домашні тапочки', [303], false],
            'протилежний вибір до явного запиту покупця' => ['Хочу вуличні капці, чорні 38/39', 'М’які домашні тапочки', [202], true],
            'домашній запит важливіший за вуличний підпис' => ['Хочу домашні пухнасті капці', 'Вуличні капці', [303], true],
            'домашній товар під вуличним підписом' => ['Беру чорні 38/39', 'Вуличні капці на літо', [202], true],
            'відповідна категорія зберігає рішення' => ['Беру чорні 38/39', 'М’які домашні тапочки', [202], false],
            'два призначення в коментарі не звужуються підписом' => ['Хочу домашні капці й вуличні капці', 'Домашні капці', [303], false],
            'два призначення в підписі не звужуються довільно' => ['Беру чорні 38/39', 'Домашні капці й вуличні капці', [303], false],
            'без явної категорії сервер не вигадує обмеження' => ['Беру чорні 38/39', 'М’які капці з хутром', [303], false],
            'окрема назва групи також визначає призначення товару' => ['Беру сірі 38/39', 'М’які домашні тапочки', [404], true],
            'одна неправильна позиція скасовує весь суперечливий вибір' => ['Дві пари 38/39', 'Домашні капці', [202, 303], true],
        ];
    }

    public function test_delivery_and_non_sales_intents_accept_clear_results_without_products(): void
    {
        $sequence = Http::sequence();
        foreach (['delivery', 'praise', 'complaint', 'other'] as $intent) {
            $sequence->push($this->response($this->decision(['intent' => $intent, 'product_ids' => []])));
        }
        Http::fake(['api.openai.com/v1/responses' => $sequence]);
        foreach (['delivery', 'praise', 'complaint', 'other'] as $intent) {
            $decision = $this->decision(['intent' => $intent, 'product_ids' => []]);

            $this->assertSame($decision, app(CommentDecision::class)->decide(new InboxComment(['text' => 'Текст коментаря']), [], $this->catalog()));
        }
        Http::assertSentCount(4);
    }

    #[DataProvider('invalidDecisions')]
    public function test_invalid_or_contradictory_model_decisions_are_rejected(array $changes): void
    {
        $decision = $this->decision($changes);
        if (($changes['intent'] ?? null) === '__missing__') unset($decision['intent']);
        $this->fakeDecision($decision);
        $this->expectException(GptFailure::class);

        app(CommentDecision::class)->decide(new InboxComment(['text' => 'Ціна?']), [], $this->catalog());
    }

    public static function invalidDecisions(): array
    {
        return [
            'невідомий товар' => [['product_ids' => [999]]],
            'дубль товару' => [['product_ids' => [101, 101]]],
            'рядок замість id' => [['product_ids' => ['101']]],
            'асоціативний перелік' => [['product_ids' => ['товар' => 101]]],
            'понад п’ять товарів' => [['product_ids' => [101, 202, 303, 404, 505, 606]]],
            'невпевненість із товаром' => [['confidence' => 'uncertain']],
            'упевненість без товару' => [['product_ids' => []]],
            'похвала з продажним товаром' => [['intent' => 'praise']],
            'скарга з продажним товаром' => [['intent' => 'complaint']],
            'зайва ціна' => [['price' => '1.00']],
            'невідомий намір' => [['intent' => 'discount']],
            'числова впевненість' => [['confidence' => 1]],
            'рядок замість прапорця кольору' => [['has_color' => 'true']],
            'число замість прапорця розміру' => [['has_size' => 1]],
            'відсутній обов’язковий ключ' => [['intent' => '__missing__']],
        ];
    }

    public function test_invalid_json_wrong_tools_and_unstructured_text_are_rejected(): void
    {
        $call = ['type' => 'function_call', 'name' => 'comment_decision', 'call_id' => 'comment-call', 'arguments' => json_encode($this->decision())];
        $cases = [
            [array_replace($call, ['arguments' => '{broken'])],
            [array_replace($call, ['name' => 'update_order'])],
            [$call, array_replace($call, ['call_id' => 'duplicate-call'])],
            [],
            [$call, ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Ціна — 1 грн']]]],
        ];
        $sequence = Http::sequence();
        foreach ($cases as $output) $sequence->push(['status' => 'completed', 'output' => $output]);
        Http::fake(['api.openai.com/v1/responses' => $sequence]);
        foreach ($cases as $output) {
            try {
                app(CommentDecision::class)->decide(new InboxComment(['text' => 'Ціна?']), [], $this->catalog());
                $this->fail('Некоректна відповідь не повинна проходити перевірку.');
            } catch (GptFailure $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        Http::assertSentCount(count($cases));
    }

    public function test_missing_credentials_reject_before_network_and_fallback_is_available(): void
    {
        AiChannelSetting::current()->update(['gpt_api_key' => null]);
        try {
            app(CommentDecision::class)->decide(new InboxComment(['text' => 'Ціна?']), [], $this->catalog());
            $this->fail('Запит без ключа не повинен надсилатися.');
        } catch (GptFailure) {
            $this->assertSame('price', app(CommentDecision::class)->fallback('Ціна?')['intent']);
        }
        Http::assertNothingSent();
    }

    public function test_api_outage_is_propagated_without_retrying_or_guessing_a_product(): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::response([], 503)]);
        try {
            app(CommentDecision::class)->decide(new InboxComment(['text' => 'Яка ціна?']), [], $this->catalog());
            $this->fail('Помилка API має перейти до викликача.');
        } catch (GptFailure) {
            $this->assertSame($this->decision(['product_ids' => [], 'confidence' => 'uncertain']), app(CommentDecision::class)->fallback('Яка ціна?'));
        }
        Http::assertSentCount(1);
    }

    public function test_context_is_bounded_and_remote_image_urls_are_never_forwarded(): void
    {
        $this->fakeDecision($this->decision());
        $context = ['text' => str_repeat('а', 4100), 'images' => array_fill(0, 4, 'data:image/png;base64,YQ==')];
        app(CommentDecision::class)->decide(new InboxComment(['text' => 'Ціна?']), $context, $this->catalog());
        Http::assertSent(function (Request $request): bool {
            $content = $request['input'][0]['content'];
            $this->assertCount(4, $content);
            $this->assertSame(4000, mb_strlen(json_decode($content[0]['text'], true)['post_caption']));

            return true;
        });
        try {
            app(CommentDecision::class)->decide(new InboxComment(['text' => 'Ціна?']), ['images' => ['https://example.com/image.png']], $this->catalog());
            $this->fail('Зображення має бути перевіреним data URL.');
        } catch (GptFailure) {
            Http::assertSentCount(1);
        }
    }

    #[DataProvider('fallbackExamples')]
    public function test_fallback_preserves_intent_without_model_calls_or_product_guesses(string $text, string $intent, bool $hasColor, bool $hasSize): void
    {
        $this->assertSame(['intent' => $intent, 'product_ids' => [], 'confidence' => 'uncertain', 'has_color' => $hasColor, 'has_size' => $hasSize],
            app(CommentDecision::class)->fallback($text));
        Http::assertNothingSent();
    }

    public static function fallbackExamples(): array
    {
        return [
            'дефект без формального слова скарга' => ['Розтягнулись і стали дуже великі', 'complaint', false, false],
            'скарга важливіша за питання ціни' => ['Відклеїлась підошва, яка ціна заміни?', 'complaint', false, false],
            'негативний відгук не є похвалою' => ['Не подобаються, якість погана', 'complaint', false, false],
            'повернення важливіше за оформлення' => ['Хочу оформити повернення чорних 38/39', 'complaint', true, true],
            'прохання людини' => ['Можна поговорити з людиною?', 'complaint', false, false],
            'прохання менеджера' => ['Покличте менеджера', 'complaint', false, false],
            'прохання оператора одним словом' => ['Оператора!', 'complaint', false, false],
            'позитивна згадка менеджера' => ['Дякую менеджеру, усе супер!', 'praise', false, false],
            'похвала без продажу' => ['Дуже гарні, дякую ❤️', 'praise', false, false],
            'похвала розміру не новий підбір' => ['Гарні чорні, розмір 38/39 підійшов, дякую!', 'praise', true, true],
            'подяка за доставку' => ['Дякую за швидку доставку!', 'praise', false, false],
            'подяка з питанням про ціну' => ['Дякую! Яка ціна?', 'price', false, false],
            'замовлення відомого вибору' => ['Беру чорні 38/39', 'order', true, true],
            'ціна не є розміром' => ['Ціна 380 грн?', 'price', false, false],
            'наявність не є дефектом' => ['Немає 38?', 'availability', false, true],
            'наявність кольору' => ['Чи є сірі?', 'availability', true, false],
            'наявність із розміром' => ['Є в наявності бежеві 40/41?', 'availability', true, true],
            'запит сітки' => ['Які розміри є?', 'size', false, false],
            'запит підбору' => ['Стопа 24 см, який краще взяти?', 'size', false, false],
            'доставка' => ['Можна відправити на поштомат?', 'delivery', false, false],
            'інший текст' => ['Доброго дня', 'other', false, false],
            'порожній коментар' => ['', 'other', false, false],
        ];
    }

    private function decision(array $changes = []): array
    {
        return array_replace(['intent' => 'price', 'product_ids' => [101], 'confidence' => 'clear', 'has_color' => false, 'has_size' => false], $changes);
    }

    private function fakeDecision(array $decision): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->response($decision))]);
    }

    private function response(array $decision): array
    {
        return ['status' => 'completed', 'output' => [['type' => 'function_call', 'name' => 'comment_decision', 'call_id' => 'comment-decision',
            'arguments' => json_encode($decision, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]]];
    }

    private function catalog(): array
    {
        return [
            ['id' => 101, 'title' => 'Домашні капці Ведмедики бежеві', 'groups' => [['id' => 11, 'name' => 'Teddy Bear', 'description' => 'Капці з принтом ведмедиків, без однотонного пухнастого верху.']],
                'color' => 'бежевий', 'sizes' => ['38/39', '40/41'], 'price' => '380.00', 'available' => true, 'cost_price' => 'secret-test-cost'],
            ['id' => 202, 'title' => 'Домашні пухнасті капці чорні', 'groups' => [['id' => 22, 'name' => 'Пухнасті', 'description' => 'Однотонний пухнастий верх, без принту.']],
                'color' => 'чорний', 'sizes' => ['38/39'], 'price' => '399.00', 'available' => true],
        ];
    }

    private function purposeCatalog(): array
    {
        return [...$this->catalog(),
            ['id' => 303, 'title' => 'Вуличні капці чорні', 'groups' => [['id' => 33, 'name' => 'Вуличні капці', 'description' => 'Для прогулянок у теплу погоду.']],
                'color' => 'чорний', 'sizes' => ['38/39'], 'price' => '530.00', 'available' => true],
            ['id' => 404, 'title' => 'Капці сірі', 'groups' => [['id' => 33, 'name' => 'Вуличні', 'description' => 'Для прогулянок у теплу погоду.']],
                'color' => 'сірий', 'sizes' => ['38/39'], 'price' => '530.00', 'available' => true],
        ];
    }
}
