<?php

namespace App\Http\Controllers;

use App\Models\ChatAiAgent;
use App\Models\ChatAiKnowledgeItem;
use App\Models\ChatAiProductModelMap;
use App\Models\ChatAiPromptVersion;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ChatAiSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ChatAiBaseController extends Controller
{
    private const STAGES = ['interest', 'selection', 'checkout_ready', 'checkout'];
    private const KNOWLEDGE_TYPES = ['instruction', 'template', 'faq'];

    public function __construct(
        private readonly ChatAiSettingsService $chatAiSettingsService
    ) {
    }

    public function index(Request $request)
    {
        if (!$request->expectsJson()) {
            return view('settings.ai-base');
        }

        $settings = $this->chatAiSettingsService->get();
        $agents = ChatAiAgent::query()
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'is_active', 'model']);

        $selectedAgentCode = (string) $request->query('agent_code', $settings['default_agent_code']);
        $selectedAgent = $agents->firstWhere('code', $selectedAgentCode) ?: $agents->first();

        $prompts = [];
        foreach (self::STAGES as $stage) {
            $prompt = null;
            if ($selectedAgent) {
                $prompt = ChatAiPromptVersion::query()
                    ->where('agent_id', $selectedAgent->id)
                    ->where('stage', $stage)
                    ->where('is_current', true)
                    ->latest('id')
                    ->first();
            }

            $prompts[$stage] = [
                'id' => $prompt?->id,
                'stage' => $stage,
                'version' => $prompt?->version,
                'system_prompt' => (string) ($prompt?->system_prompt ?? ''),
                'policy_json' => is_array($prompt?->policy_json) ? $prompt->policy_json : [],
                'updated_at' => $prompt?->updated_at?->toDateTimeString(),
            ];
        }

        $knowledgeItems = ChatAiKnowledgeItem::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get([
                'id',
                'key',
                'title',
                'item_type',
                'content',
                'sort_order',
                'is_active',
                'updated_at',
            ]);

        $modelMaps = ChatAiProductModelMap::query()
            ->with([
                'product:id,title,sku,sale_price,is_active',
                'variant:id,product_id,size,sku,stock_qty,is_active',
                'color:id,name',
            ])
            ->orderBy('priority')
            ->orderBy('id')
            ->get([
                'id',
                'model_phrase',
                'product_id',
                'variant_id',
                'color_id',
                'size_hint',
                'priority',
                'notes',
                'is_active',
                'updated_at',
            ]);

        $products = Product::query()
            ->where('is_active', true)
            ->orderBy('title')
            ->limit(1500)
            ->get(['id', 'title', 'sku', 'sale_price', 'stock_qty', 'is_active']);

        $colors = Color::query()
            ->orderBy('name')
            ->get(['id', 'name', 'hex_code']);

        return response()->json([
            'settings' => $settings,
            'selected_agent_code' => $selectedAgent?->code,
            'agents' => $agents,
            'prompts' => $prompts,
            'knowledge_items' => $knowledgeItems,
            'model_maps' => $modelMaps,
            'products' => $products,
            'colors' => $colors,
        ]);
    }

    public function variants(Product $product): JsonResponse
    {
        $variants = ProductVariant::query()
            ->where('product_id', $product->id)
            ->orderByDesc('is_active')
            ->orderBy('size')
            ->orderBy('id')
            ->get(['id', 'product_id', 'size', 'sku', 'stock_qty', 'is_active']);

        return response()->json([
            'data' => $variants,
        ]);
    }

    public function savePrompt(Request $request, string $stage): JsonResponse
    {
        if (!in_array($stage, self::STAGES, true)) {
            abort(404);
        }

        $validated = $request->validate([
            'agent_code' => ['required', 'string', 'max:80', Rule::exists('chat_ai_agents', 'code')],
            'system_prompt' => ['required', 'string', 'min:20'],
            'policy_json' => ['nullable'],
        ]);

        $policy = $this->normalizePolicyJson($validated['policy_json'] ?? null);
        $agent = ChatAiAgent::query()
            ->where('code', $validated['agent_code'])
            ->firstOrFail();

        $created = DB::transaction(function () use ($agent, $stage, $validated, $policy, $request) {
            $current = ChatAiPromptVersion::query()
                ->where('agent_id', $agent->id)
                ->where('stage', $stage)
                ->where('is_current', true)
                ->latest('id')
                ->first();

            $nextVersion = $current ? ((int) $current->version + 1) : 1;

            ChatAiPromptVersion::query()
                ->where('agent_id', $agent->id)
                ->where('stage', $stage)
                ->where('is_current', true)
                ->update([
                    'is_current' => false,
                    'updated_at' => now(),
                ]);

            return ChatAiPromptVersion::query()->create([
                'agent_id' => $agent->id,
                'stage' => $stage,
                'version' => $nextVersion,
                'system_prompt' => trim((string) $validated['system_prompt']),
                'policy_json' => $policy,
                'is_current' => true,
                'created_by' => $request->user()?->id,
            ]);
        });

        return response()->json([
            'message' => "Шаблон етапу {$stage} збережено (v{$created->version}).",
            'prompt' => [
                'id' => $created->id,
                'stage' => $created->stage,
                'version' => $created->version,
                'system_prompt' => $created->system_prompt,
                'policy_json' => is_array($created->policy_json) ? $created->policy_json : [],
                'updated_at' => $created->updated_at?->toDateTimeString(),
            ],
        ]);
    }

    public function storeKnowledgeItem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['nullable', 'string', 'max:80', Rule::unique('chat_ai_knowledge_items', 'key')],
            'title' => ['required', 'string', 'max:160'],
            'item_type' => ['required', Rule::in(self::KNOWLEDGE_TYPES)],
            'content' => ['required', 'string', 'min:3'],
            'sort_order' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'is_active' => ['required', 'boolean'],
        ]);

        $key = trim((string) ($validated['key'] ?? ''));
        if ($key === '') {
            $key = Str::limit(Str::slug((string) $validated['title'], '_'), 70, '');
            if ($key === '') {
                $key = 'knowledge_item';
            }
            $baseKey = $key;
            $counter = 1;
            while (ChatAiKnowledgeItem::query()->where('key', $key)->exists()) {
                $counter++;
                $key = Str::limit("{$baseKey}_{$counter}", 80, '');
            }
        }

        $item = ChatAiKnowledgeItem::query()->create([
            'key' => $key,
            'title' => trim((string) $validated['title']),
            'item_type' => $validated['item_type'],
            'content' => trim((string) $validated['content']),
            'sort_order' => (int) ($validated['sort_order'] ?? 100),
            'is_active' => (bool) $validated['is_active'],
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Елемент бази знань створено.',
            'item' => $item->fresh(),
        ]);
    }

    public function updateKnowledgeItem(Request $request, ChatAiKnowledgeItem $item): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:80', Rule::unique('chat_ai_knowledge_items', 'key')->ignore($item->id)],
            'title' => ['required', 'string', 'max:160'],
            'item_type' => ['required', Rule::in(self::KNOWLEDGE_TYPES)],
            'content' => ['required', 'string', 'min:3'],
            'sort_order' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'is_active' => ['required', 'boolean'],
        ]);

        $item->fill([
            'key' => trim((string) $validated['key']),
            'title' => trim((string) $validated['title']),
            'item_type' => $validated['item_type'],
            'content' => trim((string) $validated['content']),
            'sort_order' => (int) ($validated['sort_order'] ?? 100),
            'is_active' => (bool) $validated['is_active'],
            'updated_by' => $request->user()?->id,
        ]);
        $item->save();

        return response()->json([
            'message' => 'Елемент бази знань оновлено.',
            'item' => $item->fresh(),
        ]);
    }

    public function deleteKnowledgeItem(ChatAiKnowledgeItem $item): JsonResponse
    {
        $item->delete();

        return response()->json([
            'message' => 'Елемент бази знань видалено.',
        ]);
    }

    public function storeModelMap(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'model_phrase' => ['required', 'string', 'max:160'],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')],
            'color_id' => ['nullable', 'integer', Rule::exists('colors', 'id')],
            'size_hint' => ['nullable', 'string', 'max:50'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ]);

        $this->validateVariantOwnership($validated['variant_id'] ?? null, (int) $validated['product_id']);

        $map = ChatAiProductModelMap::query()->create([
            'model_phrase' => trim((string) $validated['model_phrase']),
            'product_id' => (int) $validated['product_id'],
            'variant_id' => $validated['variant_id'] ?? null,
            'color_id' => $validated['color_id'] ?? null,
            'size_hint' => trim((string) ($validated['size_hint'] ?? '')) ?: null,
            'priority' => (int) ($validated['priority'] ?? 100),
            'notes' => trim((string) ($validated['notes'] ?? '')) ?: null,
            'is_active' => (bool) $validated['is_active'],
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Мапінг моделі створено.',
            'map' => $map->fresh(['product:id,title,sku,sale_price,is_active', 'variant:id,product_id,size,sku,stock_qty,is_active', 'color:id,name']),
        ]);
    }

    public function updateModelMap(Request $request, ChatAiProductModelMap $modelMap): JsonResponse
    {
        $validated = $request->validate([
            'model_phrase' => ['required', 'string', 'max:160'],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')],
            'color_id' => ['nullable', 'integer', Rule::exists('colors', 'id')],
            'size_hint' => ['nullable', 'string', 'max:50'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ]);

        $this->validateVariantOwnership($validated['variant_id'] ?? null, (int) $validated['product_id']);

        $modelMap->fill([
            'model_phrase' => trim((string) $validated['model_phrase']),
            'product_id' => (int) $validated['product_id'],
            'variant_id' => $validated['variant_id'] ?? null,
            'color_id' => $validated['color_id'] ?? null,
            'size_hint' => trim((string) ($validated['size_hint'] ?? '')) ?: null,
            'priority' => (int) ($validated['priority'] ?? 100),
            'notes' => trim((string) ($validated['notes'] ?? '')) ?: null,
            'is_active' => (bool) $validated['is_active'],
            'updated_by' => $request->user()?->id,
        ]);
        $modelMap->save();

        return response()->json([
            'message' => 'Мапінг моделі оновлено.',
            'map' => $modelMap->fresh(['product:id,title,sku,sale_price,is_active', 'variant:id,product_id,size,sku,stock_qty,is_active', 'color:id,name']),
        ]);
    }

    public function deleteModelMap(ChatAiProductModelMap $modelMap): JsonResponse
    {
        $modelMap->delete();

        return response()->json([
            'message' => 'Мапінг моделі видалено.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizePolicyJson(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value)) {
            throw ValidationException::withMessages([
                'policy_json' => 'policy_json має бути JSON-обʼєктом.',
            ]);
        }

        $decoded = json_decode(trim($value), true);
        if (!is_array($decoded)) {
            throw ValidationException::withMessages([
                'policy_json' => 'Невалідний JSON у policy_json.',
            ]);
        }

        return $decoded;
    }

    private function validateVariantOwnership(?int $variantId, int $productId): void
    {
        if (!$variantId) {
            return;
        }

        $belongs = ProductVariant::query()
            ->where('id', $variantId)
            ->where('product_id', $productId)
            ->exists();

        if ($belongs) {
            return;
        }

        throw ValidationException::withMessages([
            'variant_id' => 'Обраний варіант не належить цьому товару.',
        ]);
    }
}

