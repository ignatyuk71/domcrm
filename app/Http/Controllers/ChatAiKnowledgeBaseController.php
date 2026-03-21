<?php

namespace App\Http\Controllers;

use App\Models\ChatAiResponseRule;
use App\Models\ChatAiSetting;
use App\Models\ChatAiTopic;
use App\Models\ChatAiTopicKeyword;
use App\Models\ChatAiTopicProduct;
use Illuminate\Contracts\View\View;

class ChatAiKnowledgeBaseController extends Controller
{
    public function index(): View
    {
        $settings = ChatAiSetting::current();

        $stats = [
            'topics_total' => ChatAiTopic::query()->count(),
            'topics_active' => ChatAiTopic::query()->where('is_active', true)->count(),
            'keywords_total' => ChatAiTopicKeyword::query()->where('is_active', true)->count(),
            'linked_products_total' => ChatAiTopicProduct::query()->where('is_active', true)->count(),
            'rules_total' => ChatAiResponseRule::query()->count(),
            'rules_active' => ChatAiResponseRule::query()->where('is_active', true)->count(),
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
            ->limit(8)
            ->get();

        $rules = ChatAiResponseRule::query()
            ->orderByDesc('is_active')
            ->orderBy('priority')
            ->orderBy('title')
            ->limit(8)
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

        return view('settings.ai-knowledge', compact(
            'settings',
            'stats',
            'topics',
            'rules',
            'recommendedTopics',
            'recommendedRules',
        ));
    }
}
