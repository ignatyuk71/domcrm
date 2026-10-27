<?php

namespace App\Modules\Gpt\Application;

use App\Models\User;
use App\Modules\Gpt\Domain\DialogueProtocol;
use App\Modules\Gpt\Domain\DraftFailure;
use App\Modules\Gpt\Domain\DraftValidator;
use App\Modules\Gpt\Infrastructure\CatalogReader;
use App\Modules\Gpt\Infrastructure\ResponsesClient;
use App\Modules\Gpt\Models\GptRun;
use App\Modules\Gpt\Models\GptSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class DraftService
{
    public function __construct(
        private CatalogReader $catalog,
        private ResponsesClient $client,
        private DialogueProtocol $protocol,
        private DraftValidator $validator,
    ) {}

    public function generate(GptRun $run): void
    {
        if (in_array($run->status, ['completed', 'failed'], true)) {
            return;
        }
        $started = microtime(true);
        $evidence = [];
        $usage = ['input_tokens' => 0, 'output_tokens' => 0, 'requests' => 0];
        try {
            $settings = $this->settingsFor($run);
            $run->update(['status' => 'running', 'started_at' => now(), 'error_code' => null, 'error_message' => null]);
            $dialogue = $run->dialogue;
            $memory = $dialogue->memory ?? [];
            $groups = $offers = [];
            $catalogProducts = [];
            $catalogHasMore = false;
            $input = [];
            $previous = $dialogue->runs()->where('status', 'completed')->where('id', '<', $run->id)
                ->latest('id')->limit(6)->get()->reverse();
            // У контексті зберігаємо останній набір фото, щоб не дублювати великі файли.
            $lastImageRun = preg_match('~(?:^|\n)Фото:\s*https?://~iu', $run->input_message) ? null
                : $previous->last(fn ($past) => preg_match('~(?:^|\n)Фото:\s*https?://~iu', $past->input_message))?->id;
            foreach ($previous as $past) {
                $input[] = ['role' => 'user', 'content' => $past->id === $lastImageRun
                    ? $this->userContent($past->input_message, $past->input_message, $run->created_by) : $past->input_message];
                $input[] = ['role' => 'assistant', 'content' => $past->result['message'] ?? ''];
            }
            $input[] = ['role' => 'user', 'content' => $this->userContent(json_encode([
                'store_policies' => $settings->store_policies ?: 'Умови магазину ще не задано; їх потрібно уточнювати.',
                'confirmed_selection' => $memory,
                'message' => $run->input_message,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $run->input_message, $run->created_by)];

            $groupId = $run->gallery_group_id ?: ($memory['group_id'] ?? null);
            if ($groupId) {
                $data = $this->catalog->describe((int) $groupId);
                $groups[$data['id']] = $data;
                $evidence[] = ['tool' => 'describe_model', 'data' => $data];
                $input[] = ['role' => 'user', 'content' => 'Підтверджені дані вибраної групи: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
            }

            // Ціна та наявність потрапляють у контекст до першої відповіді GPT.
            if (! $run->gallery_group_id) {
                $catalogData = $this->catalog->searchProducts($run->input_message);
                $catalogProducts = array_column($catalogData['products'], 'id');
                $catalogHasMore = $catalogData['has_more'];
                foreach ($catalogData['products'] as $offer) {
                    $offers[$offer['id']] = $offer;
                }
                if ($catalogProducts !== []) {
                    $evidence[] = ['tool' => 'search_products', 'data' => $catalogData];
                    $input[] = ['role' => 'user', 'content' => 'Актуальні товари CRM за поточним запитом: '.json_encode($catalogData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
                }
            }
            if (! empty($memory['product_id']) && ! isset($offers[$memory['product_id']])) {
                try {
                    $offer = $this->catalog->offer((int) $memory['product_id']);
                    $offers[$offer['id']] = $offer;
                    $evidence[] = ['tool' => 'get_product_offer', 'data' => $offer];
                    $input[] = ['role' => 'user', 'content' => 'Актуальні дані раніше вибраного товару: '.json_encode($offer, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
                } catch (DraftFailure $error) {
                    if ($error->reason !== 'product_unavailable') {
                        throw $error;
                    }
                    $input[] = ['role' => 'user', 'content' => 'Раніше вибраний товар більше недоступний. Запропонуй новий пошук.'];
                }
            }

            $toolCount = 0;
            for ($round = 0; $round < (int) config('gpt-drafts.max_rounds'); $round++) {
                $settings = $this->settingsFor($run);
                $remaining = (int) floor((int) config('gpt-drafts.deadline') - (microtime(true) - $started));
                if ($remaining < 1 || $this->textContextSize($input) > (int) config('gpt-drafts.max_input_chars')) {
                    throw new DraftFailure('budget', 'Досягнуто ліміту часу або контексту. Почніть новий тестовий діалог.');
                }
                $response = $this->client->request($settings->key(), [
                    'model' => $run->model, 'store' => false,
                    'instructions' => $this->protocol->instructions(), 'input' => $input,
                    'tools' => $this->protocol->tools(), 'parallel_tool_calls' => false,
                    'text' => ['format' => $this->protocol->format()], 'max_output_tokens' => 2000,
                    'include' => ['reasoning.encrypted_content'],
                ], $remaining);
                $usage['requests']++;
                $usage['input_tokens'] += (int) ($response['usage']['input_tokens'] ?? 0);
                $usage['output_tokens'] += (int) ($response['usage']['output_tokens'] ?? 0);
                $calls = array_values(array_filter($response['output'], fn ($item) => ($item['type'] ?? '') === 'function_call'));
                if ($calls) {
                    $input = array_merge($input, $response['output']);
                    foreach ($calls as $call) {
                        if (++$toolCount > (int) config('gpt-drafts.max_tool_calls')) {
                            throw new DraftFailure('tool_limit', 'Досягнуто ліміту звернень до каталогу. Уточніть запит.');
                        }
                        $data = $this->executeTool($call, $groups, $offers);
                        if ($call['name'] === 'search_products') {
                            $catalogProducts = array_column($data['products'], 'id');
                            $catalogHasMore = $data['has_more'];
                        }
                        $evidence[] = ['tool' => $call['name'], 'data' => $data];
                        $input[] = ['type' => 'function_call_output', 'call_id' => $call['call_id'],
                            'output' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
                    }

                    continue;
                }
                $text = '';
                foreach ($response['output'] as $item) {
                    foreach ($item['content'] ?? [] as $part) {
                        if (($part['type'] ?? '') === 'refusal') {
                            throw new DraftFailure('refusal', 'OpenAI відмовився обробляти цей запит. Повідомлення збережено.');
                        }
                        if (($part['type'] ?? '') === 'output_text') {
                            $text .= $part['text'] ?? '';
                        }
                    }
                }
                $draft = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
                if (! is_array($draft)) {
                    throw new DraftFailure('invalid_draft', 'OpenAI повернув непридатну чернетку.');
                }
                // Не приховуємо знайдені ціни, якщо модель повернула загальну відповідь.
                if (in_array($draft['action'] ?? null, ['reply', 'clarify'], true) && empty($draft['product_id'])
                    && empty($draft['product_ids']) && $catalogProducts !== []) {
                    $draft['product_ids'] = $catalogProducts;
                    $draft['message'] = $catalogHasMore
                        ? 'Ось частина знайдених товарів. Уточніть назву або колір, щоб звузити пошук.'
                        : 'Ось товари, знайдені в каталозі. Можу допомогти з вибором.';
                    $draft['group_id'] = null;
                    $draft['size'] = null;
                    $draft['photo_ids'] = [];
                }
                $result = $this->validator->prepare($draft, $groups, $offers);
                $this->settingsFor($run);
                $usage['elapsed_ms'] = (int) round((microtime(true) - $started) * 1000);
                DB::transaction(function () use ($run, $result, $evidence, $usage, $dialogue, $draft) {
                    $run->update(['status' => 'completed', 'result' => $result, 'evidence' => $evidence,
                        'usage' => $usage, 'finished_at' => now()]);
                    $dialogue->update(['memory' => [
                        'group_id' => $draft['group_id'], 'product_id' => $draft['product_id'], 'size' => $draft['size'],
                    ]]);
                });

                return;
            }
            throw new DraftFailure('round_limit', 'GPT не завершив чернетку за дозволену кількість кроків. Уточніть запит.');
        } catch (Throwable $error) {
            $usage['elapsed_ms'] = (int) round((microtime(true) - $started) * 1000);
            $run->update(['status' => 'failed', 'result' => null, 'evidence' => $evidence, 'usage' => $usage,
                'error_code' => $error instanceof DraftFailure ? $error->reason : 'generation_failed',
                'error_message' => $error instanceof DraftFailure ? $error->getMessage() : 'Не вдалося підготувати чернетку. Повідомлення збережено; повторіть спробу.',
                'finished_at' => now()]);
        }
    }

    private function userContent(string $text, string $message, int $ownerId): string|array
    {
        // Приватні вкладення передаємо як дані; зовнішні посилання сервер не завантажує.
        preg_match_all('~(?:^|\n)Фото:\s*(https?://[^\s<>]+)~iu', $message, $matches);
        $urls = array_slice(array_values(array_unique(array_filter($matches[1] ?? [],
            fn ($url) => filter_var($url, FILTER_VALIDATE_URL) !== false))), 0, 3);
        if ($urls === []) {
            return $text;
        }

        return array_merge([['type' => 'input_text', 'text' => $text]], array_map(function ($url) use ($ownerId) {
            $path = parse_url($url, PHP_URL_PATH) ?: '';
            if (parse_url($url, PHP_URL_HOST) === parse_url(config('app.url'), PHP_URL_HOST)
                && preg_match('~\A/settings/gpt/attachments/([a-f0-9-]{36}\.(jpg|png|webp))\z~', $path, $match)) {
                $file = 'gpt-drafts/uploads/'.$ownerId.'/'.$match[1];
                if (! Storage::disk('local')->exists($file)) {
                    throw new DraftFailure('missing_attachment', 'Фото недоступне. Завантажте його повторно.');
                }
                $mime = match ($match[2]) {
                    'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'
                };
                $url = 'data:'.$mime.';base64,'.base64_encode(Storage::disk('local')->get($file));
            }

            return ['type' => 'input_image', 'image_url' => $url, 'detail' => 'auto'];
        }, $urls));
    }

    private function textContextSize(array $input): int
    {
        // Бінарні фото мають окремий ліміт завантаження, а не ліміт тексту.
        foreach ($input as &$item) {
            if (! is_array($item['content'] ?? null)) {
                continue;
            }
            foreach ($item['content'] as &$part) {
                if (($part['type'] ?? '') === 'input_image' && str_starts_with($part['image_url'] ?? '', 'data:')) {
                    $part['image_url'] = '[завантажене фото]';
                }
            }
            unset($part);
        }
        unset($item);

        return strlen(json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function settingsFor(GptRun $run): GptSetting
    {
        $owner = User::find($run->created_by);
        $settings = GptSetting::current();
        if (! $owner?->isOwner() || ! $owner->isActive() || ! $settings->enabled || ! $settings->key()
            || (int) $settings->version !== (int) $run->settings_version || $settings->modelName() !== $run->model) {
            throw new DraftFailure('settings_changed', 'GPT вимкнено або налаштування змінилися. Перевірте їх і повторіть спробу.');
        }

        return $settings;
    }

    private function executeTool(array $call, array &$groups, array &$offers): array
    {
        $args = json_decode($call['arguments'] ?? '', true, 16, JSON_THROW_ON_ERROR);
        $rules = match ($call['name'] ?? '') {
            'search_products' => ['args' => 'required|array:query,size', 'args.query' => 'required|string|max:300', 'args.size' => 'present|nullable|string|max:50'],
            'search_models' => ['args' => 'required|array:query', 'args.query' => 'required|string|max:100'],
            'describe_model' => ['args' => 'required|array:group_id', 'args.group_id' => 'required|integer|min:1'],
            'get_product_offer' => ['args' => 'required|array:product_id,size', 'args.product_id' => 'required|integer|min:1', 'args.size' => 'present|nullable|string|max:50'],
            default => throw new DraftFailure('invalid_tool', 'GPT запросив недозволену дію.'),
        };
        if (! is_array($args) || empty($call['call_id']) || Validator::make(['args' => $args], $rules)->fails()) {
            throw new DraftFailure('invalid_tool', 'GPT передав некоректні параметри інструмента.');
        }

        if ($call['name'] === 'search_models') {
            return $this->catalog->search($args['query']);
        }
        if ($call['name'] === 'search_products') {
            $data = $this->catalog->searchProducts($args['query'], $args['size']);
            foreach ($data['products'] as $offer) {
                $offers[$offer['id']] = $offer;
            }

            return $data;
        }
        if ($call['name'] === 'describe_model') {
            $data = $this->catalog->describe((int) $args['group_id']);
            $groups[$data['id']] = $data;

            return $data;
        }
        $data = $this->catalog->offer((int) $args['product_id'], $args['size']);
        $offers[$data['id']] = $data;

        return $data;
    }
}
