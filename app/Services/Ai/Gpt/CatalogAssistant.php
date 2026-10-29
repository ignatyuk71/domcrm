<?php

namespace App\Services\Ai\Gpt;

use App\Models\Product;
use App\Services\Ai\Gpt\CatalogReader;
use App\Services\Ai\Gpt\OpenAiClient;
use Illuminate\Support\Facades\Validator;

class CatalogAssistant
{
    public function __construct(private OpenAiClient $client, private CatalogReader $catalog, private CatalogProtocol $protocol,
        private CatalogBuilder $storeCatalog, private TextScrubber $scrubber, private CheckoutPricing $pricing) {}

    public function reply(#[\SensitiveParameter] string $key, string $model, array $input, ?array $checkoutData = null,
        ?int $connectionId = null, array $recentPhotoIds = [], array $receipt = []): array
    {
        $checkout = new CheckoutSession($this->pricing, $checkoutData, $receipt);
        $instructions = $this->protocol->instructions($connectionId);
        $history = [];
        $photos = [];
        $texts = [];
        $tokensIn = $tokensOut = 0;
        $photoClaim = false;
        $photoToolCalled = false;
        $repaired = false;
        $deadline = microtime(true) + 70;
        for ($round = 0; $round <= 5; $round++) {
            $timeout = min(40, (int) ($deadline - microtime(true)));
            if ($timeout < 1) {
                throw new GptFailure('Вичерпано час підготовки відповіді. Повідомлення збережене — можна повторити.');
            }
            $result = $this->client->respond($key, $model, $input, $instructions.CheckoutProtocol::context($checkout->data), $this->protocol->tools(), $round < 5, $timeout);
            $tokensIn += $result['tokens_in'];
            $tokensOut += $result['tokens_out'];
            array_push($input, ...$result['output']);
            array_push($history, ...$result['output']);
            $photoClaim = $photoClaim || $this->scrubber->mentionsSendingPhotos($result['text']);
            $text = $this->scrubber->stripModelArtifacts($this->scrubber->stripPhotoPlaceholder($result['text']));
            if ($text !== '') {
                $texts[] = $text;
            }
            if (! $result['calls']) {
                if ($photoClaim && ! $photoToolCalled && ! $repaired && $round < 5) {
                    $note = ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => ConversationNotes::PHOTO_REPAIR_NOTE]]];
                    $input[] = $note;
                    $history[] = $note;
                    $texts = [];
                    $photoClaim = false;
                    $repaired = true;
                    continue;
                }
                $text = implode("\n\n", array_values(array_unique($texts)));
                if ($checkout->completed) {
                    try {
                        $checkout->verifyCompletion();
                    } catch (\DomainException $e) {
                        throw new GptFailure($e->getMessage().' Повідомлення збережене — виправте вибір або повторіть.');
                    }
                    $text = trim($text."\n\n".OrderTexts::all()['final_message']);
                } elseif ($checkout->escalated) {
                    $text = OrderTexts::all()['handover'];
                }
                if ($text === '' && ! $checkout->messages && ! $photos) {
                    throw new GptFailure('OpenAI повернув порожню відповідь. Перевірте вибрану модель та повторіть.');
                }

                return ['text' => mb_substr($text, 0, 1900), 'output' => $history, 'photos' => array_values($photos),
                    'checkout' => $checkout->data, 'checkout_messages' => $checkout->messages, 'tokens_in' => $tokensIn, 'tokens_out' => $tokensOut];
            }
            if ($round === 5) {
                throw new GptFailure('Модель не завершила пошук у каталозі. Уточніть запит або повторіть.');
            }

            foreach ($result['calls'] as $call) {
                $output = $this->execute($call, $photos, $recentPhotoIds, $checkout);
                if ($call['name'] === 'send_photos') {
                    $photoToolCalled = true;
                    $output['система'] = in_array('надіслано', $output, true)
                        ? ConversationNotes::PHOTOS_SENT_NOTE : ConversationNotes::PHOTOS_SKIPPED_NOTE;
                    // Виклик інструмента, навіть із пропущеними дублями, вже виконав перевірку фото.
                    $photoClaim = false;
                }
                $item = ['type' => 'function_call_output', 'call_id' => $call['call_id'],
                    'output' => json_encode($output, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
                $input[] = $item;
                $history[] = $item;
            }
        }

        throw new GptFailure('Не вдалося завершити відповідь. Повторіть запит.');
    }

    private function execute(array $call, array &$photos, array $recentPhotoIds, CheckoutSession $checkout): array
    {
        try {
            $args = json_decode($call['arguments'] ?? '', true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($args)) {
                return ['помилка' => 'Параметри інструмента мають бути об’єктом.'];
            }
            if (in_array($call['name'], ['update_order', 'ask_delivery_details', 'send_payment_details', 'complete_order', 'escalate_to_manager'], true)) {
                return $checkout->run($call['name'], $args);
            }
            $rules = match ($call['name']) {
                'get_product' => ['product_id' => 'required|integer|min:1'],
                'send_photos' => ['photo_ids' => 'required|array|min:1', 'photo_ids.*' => 'required|integer|min:1'],
                default => [],
            };
            if (! $rules || Validator::make($args, $rules)->fails()
                || array_diff(array_keys($args), array_filter(array_keys($rules), fn ($key) => ! str_contains($key, '.')))) {
                return ['помилка' => 'Невідомий інструмент або некоректні параметри. Виправ виклик.'];
            }

            return match ($call['name']) {
                'get_product' => $this->product((int) $args['product_id']),
                'send_photos' => $this->selectPhotos($args['photo_ids'], $photos, $recentPhotoIds),
            };
        } catch (\JsonException) {
            return ['помилка' => 'Некоректний JSON параметрів. Виправ виклик інструмента.'];
        }
    }

    private function product(int $id): array
    {
        $product = Product::query()->where('is_active', true)
            ->withCount('variants')->with(['category:id,name', 'color:id,name', 'variants' => fn ($query) => $query->where('is_active', true)])->find($id);
        if (! $product) {
            return ['помилка' => 'Товар не знайдено'];
        }

        return array_merge($this->storeCatalog->productSummary($product, $this->storeCatalog->photoInfoFor([$id])), [
            'опис' => $this->scrubber->scrub($product->description ?: null),
            'розміри' => $product->variants->map(fn ($variant) => [
                'розмір' => $variant->size, 'залишок' => (int) $variant->stock_qty,
                'наявність' => $variant->stock_qty > 0 ? 'є' : 'немає',
            ])->values()->all(),
        ]);
    }

    private function selectPhotos(array $ids, array &$photos, array $recentPhotoIds): array
    {
        $ids = array_values(array_unique($ids));
        $extra = count($ids) > 3 ? 'Ліміт 3 фото за виклик — надіслано перші 3.' : null;
        $ids = array_slice($ids, 0, 3);
        $available = collect($this->catalog->photos($ids))->keyBy('id');
        $results = [];
        foreach ($ids as $id) {
            $results['фото №'.$id] = match (true) {
                ! $available->has($id) => 'не знайдено — такого номера немає',
                isset($photos[$id]), in_array($id, $recentPhotoIds, true) => 'щойно надсилалося цьому клієнту — пропущено, не дублюй',
                default => 'надіслано',
            };
            if ($results['фото №'.$id] === 'надіслано') {
                $photos[$id] = $available[$id];
            }
        }
        if ($extra) {
            $results['увага'] = $extra;
        }

        return $results;
    }
}
