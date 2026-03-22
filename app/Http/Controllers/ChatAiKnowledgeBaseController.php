<?php

namespace App\Http\Controllers;

use App\Models\ChatAiResponseRule;
use App\Models\ChatAiSetting;
use App\Models\ChatAiTopic;
use App\Models\ChatAiTopicKeyword;
use App\Models\ChatAiTopicMedia;
use App\Models\ChatAiTopicProduct;
use App\Models\Product;
use App\Models\SavedFile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChatAiKnowledgeBaseController extends Controller
{
    private function respond(Request $request, string $message, ?string $openModalId = null): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'open_modal_id' => $openModalId,
            ]);
        }

        return back()->with('success', $message);
    }

    private function topicInventoryModalId(?int $topicId): ?string
    {
        if (!$topicId) {
            return null;
        }

        return 'topicInventoryModal' . $topicId;
    }

    public function index(): View
    {
        $settings = ChatAiSetting::current();
        $isAiEnabled = (bool) ($settings?->enabled ?? false);

        $stats = [
            'topics_total' => ChatAiTopic::query()->count(),
            'topics_active' => ChatAiTopic::query()->where('is_active', true)->count(),
            'keywords_total' => ChatAiTopicKeyword::query()->where('is_active', true)->count(),
            'linked_products_total' => ChatAiTopicProduct::query()->where('is_active', true)->count(),
            'rules_total' => ChatAiResponseRule::query()->count(),
            'rules_active' => ChatAiResponseRule::query()->where('is_active', true)->count(),
            'media_total' => ChatAiTopicMedia::query()->where('is_active', true)->count(),
        ];

        $topics = ChatAiTopic::query()
            ->with([
                'topicProducts' => fn ($query) => $query
                    ->with('product:id,title,sku,sale_price,is_active')
                    ->orderBy('sort_order')
                    ->orderByDesc('is_active'),
                'mediaItems' => fn ($query) => $query
                    ->with('savedFile:id,filename,url,type')
                    ->orderBy('sort_order')
                    ->orderByDesc('is_active'),
            ])
            ->withCount([
                'keywords as positive_keywords_count' => fn ($query) => $query
                    ->where('match_type', 'positive')
                    ->where('is_active', true),
                'keywords as negative_keywords_count' => fn ($query) => $query
                    ->where('match_type', 'negative')
                    ->where('is_active', true),
                'topicProducts as linked_products_count' => fn ($query) => $query
                    ->where('is_active', true),
                'mediaItems as linked_media_count' => fn ($query) => $query
                    ->where('is_active', true),
            ])
            ->orderByDesc('is_active')
            ->orderBy('priority')
            ->orderBy('name')
            ->get();

        $keywords = ChatAiTopicKeyword::query()
            ->with('topic:id,name')
            ->orderByDesc('is_active')
            ->orderByDesc('weight')
            ->orderBy('phrase')
            ->get();

        $topicProducts = ChatAiTopicProduct::query()
            ->with([
                'topic:id,name',
                'product:id,title,sku,sale_price,is_active',
            ])
            ->orderBy('topic_id')
            ->orderBy('sort_order')
            ->get();

        $topicMedia = ChatAiTopicMedia::query()
            ->with([
                'topic:id,name',
                'savedFile:id,filename,url,type',
            ])
            ->orderBy('topic_id')
            ->orderBy('sort_order')
            ->get();

        $rules = ChatAiResponseRule::query()
            ->orderByDesc('is_active')
            ->orderBy('priority')
            ->orderBy('title')
            ->get(['id', 'code', 'title', 'instruction', 'priority', 'is_active']);

        $recommendedTopics = [
            'Домашні тапки',
            'Тапки з хутром',
            'Резинові тапки',
            'Дитячі тапки',
            'Капці для вулиці',
        ];

        $recommendedRules = [
            'Привітання',
            'Запит ціни',
            'Запит фото',
            'Запит розміру',
            'Потрібен менеджер',
            'Конфліктний клієнт',
        ];

        $products = Product::query()
            ->where('is_active', true)
            ->orderBy('title')
            ->limit(500)
            ->get(['id', 'title', 'sku', 'sale_price']);

        $savedFiles = SavedFile::query()
            ->orderByDesc('id')
            ->limit(500)
            ->get(['id', 'filename', 'url', 'type']);

        return view('settings.ai-knowledge', compact(
            'settings',
            'isAiEnabled',
            'stats',
            'topics',
            'keywords',
            'topicProducts',
            'topicMedia',
            'rules',
            'products',
            'savedFiles',
            'recommendedTopics',
            'recommendedRules',
        ));
    }

    public function storeTopic(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:chat_ai_topics,name'],
            'instruction' => ['nullable', 'string', 'max:2000'],
            'priority' => ['required', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        ChatAiTopic::query()->create([
            'name' => trim($data['name']),
            'instruction' => $data['instruction'] ?? null,
            'priority' => $data['priority'],
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        return $this->respond($request, 'Тему додано.');
    }

    public function updateTopic(Request $request, ChatAiTopic $topic): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('chat_ai_topics', 'name')->ignore($topic->id),
            ],
            'instruction' => ['nullable', 'string', 'max:2000'],
            'priority' => ['required', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $topic->update([
            'name' => trim($data['name']),
            'instruction' => $data['instruction'] ?? null,
            'priority' => $data['priority'],
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        return $this->respond($request, 'Тему оновлено.');
    }

    public function destroyTopic(Request $request, ChatAiTopic $topic): RedirectResponse|JsonResponse
    {
        $topic->delete();
        return $this->respond($request, 'Тему видалено.');
    }

    public function storeKeyword(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'topic_id' => ['required', 'exists:chat_ai_topics,id'],
            'phrase' => ['required', 'string', 'max:255'],
            'match_type' => ['required', Rule::in(['positive', 'negative'])],
            'weight' => ['required', 'integer', 'min:1', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        ChatAiTopicKeyword::query()->create([
            'topic_id' => $data['topic_id'],
            'phrase' => trim($data['phrase']),
            'match_type' => $data['match_type'],
            'weight' => $data['weight'],
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        return $this->respond($request, 'Ключове слово додано.');
    }

    public function updateKeyword(Request $request, ChatAiTopicKeyword $keyword): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'topic_id' => ['required', 'exists:chat_ai_topics,id'],
            'phrase' => ['required', 'string', 'max:255'],
            'match_type' => ['required', Rule::in(['positive', 'negative'])],
            'weight' => ['required', 'integer', 'min:1', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $keyword->update([
            'topic_id' => $data['topic_id'],
            'phrase' => trim($data['phrase']),
            'match_type' => $data['match_type'],
            'weight' => $data['weight'],
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        return $this->respond($request, 'Ключове слово оновлено.');
    }

    public function destroyKeyword(Request $request, ChatAiTopicKeyword $keyword): RedirectResponse|JsonResponse
    {
        $keyword->delete();
        return $this->respond($request, 'Ключове слово видалено.');
    }

    public function storeTopicProduct(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'topic_id' => ['required', 'exists:chat_ai_topics,id'],
            'product_id' => [
                'required',
                'exists:products,id',
                Rule::unique('chat_ai_topic_products', 'product_id')
                    ->where(fn ($query) => $query->where('topic_id', $request->input('topic_id'))),
            ],
            'sort_order' => ['required', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'product_id.unique' => 'Цей товар уже є у списку цієї теми. Виберіть інший товар.',
        ]);

        ChatAiTopicProduct::query()->create([
            'topic_id' => $data['topic_id'],
            'product_id' => $data['product_id'],
            'sort_order' => $data['sort_order'],
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        return $this->respond(
            $request,
            'Товар привʼязано до теми.',
            $this->topicInventoryModalId((int) $data['topic_id'])
        );
    }

    public function updateTopicProduct(Request $request, ChatAiTopicProduct $topicProduct): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'topic_id' => ['required', 'exists:chat_ai_topics,id'],
            'product_id' => [
                'required',
                'exists:products,id',
                Rule::unique('chat_ai_topic_products', 'product_id')
                    ->where(fn ($query) => $query->where('topic_id', $request->input('topic_id')))
                    ->ignore($topicProduct->id),
            ],
            'sort_order' => ['required', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'product_id.unique' => 'Цей товар уже є у списку цієї теми. Виберіть інший товар.',
        ]);

        $topicProduct->update([
            'topic_id' => $data['topic_id'],
            'product_id' => $data['product_id'],
            'sort_order' => $data['sort_order'],
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        return $this->respond(
            $request,
            'Привʼязку товару оновлено.',
            $this->topicInventoryModalId((int) $data['topic_id'])
        );
    }

    public function destroyTopicProduct(Request $request, ChatAiTopicProduct $topicProduct): RedirectResponse|JsonResponse
    {
        $topicId = $topicProduct->topic_id;
        $topicProduct->delete();
        return $this->respond(
            $request,
            'Привʼязку товару видалено.',
            $this->topicInventoryModalId($topicId)
        );
    }

    public function storeMedia(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'topic_id' => ['required', 'exists:chat_ai_topics,id'],
            'saved_file_id' => ['nullable', 'exists:saved_files,id'],
            'label' => ['required', 'string', 'max:255'],
            'media_type' => ['required', Rule::in(['image', 'size_chart', 'palette', 'promo', 'collage'])],
            'url' => ['nullable', 'url', 'max:1000'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        ChatAiTopicMedia::query()->create([
            'topic_id' => $data['topic_id'],
            'saved_file_id' => $data['saved_file_id'] ?? null,
            'label' => trim($data['label']),
            'media_type' => $data['media_type'],
            'url' => $data['url'] ?? null,
            'sort_order' => $data['sort_order'],
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        return $this->respond(
            $request,
            'Медіа додано.',
            $this->topicInventoryModalId((int) $data['topic_id'])
        );
    }

    public function updateMedia(Request $request, ChatAiTopicMedia $media): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'topic_id' => ['required', 'exists:chat_ai_topics,id'],
            'saved_file_id' => ['nullable', 'exists:saved_files,id'],
            'label' => ['required', 'string', 'max:255'],
            'media_type' => ['required', Rule::in(['image', 'size_chart', 'palette', 'promo', 'collage'])],
            'url' => ['nullable', 'url', 'max:1000'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $media->update([
            'topic_id' => $data['topic_id'],
            'saved_file_id' => $data['saved_file_id'] ?? null,
            'label' => trim($data['label']),
            'media_type' => $data['media_type'],
            'url' => $data['url'] ?? null,
            'sort_order' => $data['sort_order'],
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        return $this->respond(
            $request,
            'Медіа оновлено.',
            $this->topicInventoryModalId((int) $data['topic_id'])
        );
    }

    public function destroyMedia(Request $request, ChatAiTopicMedia $media): RedirectResponse|JsonResponse
    {
        $topicId = $media->topic_id;
        $media->delete();
        return $this->respond(
            $request,
            'Медіа видалено.',
            $this->topicInventoryModalId($topicId)
        );
    }

    public function storeRule(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100', 'unique:chat_ai_response_rules,code'],
            'title' => ['required', 'string', 'max:255'],
            'instruction' => ['required', 'string', 'max:3000'],
            'priority' => ['required', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        ChatAiResponseRule::query()->create([
            'code' => trim($data['code']),
            'title' => trim($data['title']),
            'instruction' => trim($data['instruction']),
            'priority' => $data['priority'],
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        return $this->respond($request, 'Сценарій додано.');
    }

    public function updateRule(Request $request, ChatAiResponseRule $rule): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'code' => [
                'required',
                'string',
                'max:100',
                Rule::unique('chat_ai_response_rules', 'code')->ignore($rule->id),
            ],
            'title' => ['required', 'string', 'max:255'],
            'instruction' => ['required', 'string', 'max:3000'],
            'priority' => ['required', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $rule->update([
            'code' => trim($data['code']),
            'title' => trim($data['title']),
            'instruction' => trim($data['instruction']),
            'priority' => $data['priority'],
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        return $this->respond($request, 'Сценарій оновлено.');
    }

    public function destroyRule(Request $request, ChatAiResponseRule $rule): RedirectResponse|JsonResponse
    {
        $rule->delete();
        return $this->respond($request, 'Сценарій видалено.');
    }
}
