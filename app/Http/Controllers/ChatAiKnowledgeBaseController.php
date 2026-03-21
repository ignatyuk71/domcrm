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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChatAiKnowledgeBaseController extends Controller
{
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
            ->withCount([
                'keywords as positive_keywords_count' => fn ($query) => $query
                    ->where('match_type', 'positive')
                    ->where('is_active', true),
                'keywords as negative_keywords_count' => fn ($query) => $query
                    ->where('match_type', 'negative')
                    ->where('is_active', true),
                'topicProducts as linked_products_count' => fn ($query) => $query
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

    public function storeTopic(Request $request): RedirectResponse
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

        return back()->with('success', 'Тему додано.');
    }

    public function updateTopic(Request $request, ChatAiTopic $topic): RedirectResponse
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

        return back()->with('success', 'Тему оновлено.');
    }

    public function destroyTopic(ChatAiTopic $topic): RedirectResponse
    {
        $topic->delete();
        return back()->with('success', 'Тему видалено.');
    }

    public function storeKeyword(Request $request): RedirectResponse
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

        return back()->with('success', 'Ключове слово додано.');
    }

    public function updateKeyword(Request $request, ChatAiTopicKeyword $keyword): RedirectResponse
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

        return back()->with('success', 'Ключове слово оновлено.');
    }

    public function destroyKeyword(ChatAiTopicKeyword $keyword): RedirectResponse
    {
        $keyword->delete();
        return back()->with('success', 'Ключове слово видалено.');
    }

    public function storeTopicProduct(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'topic_id' => ['required', 'exists:chat_ai_topics,id'],
            'product_id' => ['required', 'exists:products,id'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        ChatAiTopicProduct::query()->create([
            'topic_id' => $data['topic_id'],
            'product_id' => $data['product_id'],
            'sort_order' => $data['sort_order'],
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        return back()->with('success', 'Товар привʼязано до теми.');
    }

    public function updateTopicProduct(Request $request, ChatAiTopicProduct $topicProduct): RedirectResponse
    {
        $data = $request->validate([
            'topic_id' => ['required', 'exists:chat_ai_topics,id'],
            'product_id' => ['required', 'exists:products,id'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $topicProduct->update([
            'topic_id' => $data['topic_id'],
            'product_id' => $data['product_id'],
            'sort_order' => $data['sort_order'],
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        return back()->with('success', 'Привʼязку товару оновлено.');
    }

    public function destroyTopicProduct(ChatAiTopicProduct $topicProduct): RedirectResponse
    {
        $topicProduct->delete();
        return back()->with('success', 'Привʼязку товару видалено.');
    }

    public function storeMedia(Request $request): RedirectResponse
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

        return back()->with('success', 'Медіа додано.');
    }

    public function updateMedia(Request $request, ChatAiTopicMedia $media): RedirectResponse
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

        return back()->with('success', 'Медіа оновлено.');
    }

    public function destroyMedia(ChatAiTopicMedia $media): RedirectResponse
    {
        $media->delete();
        return back()->with('success', 'Медіа видалено.');
    }

    public function storeRule(Request $request): RedirectResponse
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

        return back()->with('success', 'Сценарій додано.');
    }

    public function updateRule(Request $request, ChatAiResponseRule $rule): RedirectResponse
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

        return back()->with('success', 'Сценарій оновлено.');
    }

    public function destroyRule(ChatAiResponseRule $rule): RedirectResponse
    {
        $rule->delete();
        return back()->with('success', 'Сценарій видалено.');
    }
}
