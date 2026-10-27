<?php

namespace App\Modules\Gpt\Domain;

use App\Modules\Gpt\Infrastructure\CatalogReader;
use Illuminate\Support\Facades\Validator;

class DraftValidator
{
    public function __construct(private CatalogReader $catalog) {}

    public function prepare(array $draft, array $seenGroups, array $seenOffers): array
    {
        $validation = Validator::make(['draft' => $draft], [
            'draft' => 'required|array:action,message,group_id,product_id,product_ids,size,photo_ids',
            'draft.action' => 'required|in:reply,clarify,handoff',
            'draft.message' => 'required|string|max:1600',
            'draft.group_id' => 'present|nullable|integer|min:1',
            'draft.product_id' => 'present|nullable|integer|min:1',
            'draft.product_ids' => 'sometimes|array|max:8',
            'draft.product_ids.*' => 'integer|distinct|min:1',
            'draft.size' => 'present|nullable|string|max:50',
            'draft.photo_ids' => 'present|array|max:3',
            'draft.photo_ids.*' => 'integer|distinct|min:1',
        ]);
        if ($validation->fails() || preg_match('~\p{N}|https?://|www\.~u', $draft['message'] ?? '')) {
            throw new DraftFailure('invalid_draft', 'Формат чернетки не пройшов перевірку. Цифри та посилання має формувати сервер.');
        }
        $groupId = $draft['group_id'] !== null ? (int) $draft['group_id'] : null;
        $productId = $draft['product_id'] !== null ? (int) $draft['product_id'] : null;
        $productIds = array_values(array_unique(array_map('intval', array_merge($draft['product_ids'] ?? [], $productId !== null ? [$productId] : []))));
        if (($groupId !== null && ! isset($seenGroups[$groupId]))
            || count($productIds) > 8 || array_diff($productIds, array_keys($seenOffers)) !== []
            || ($draft['size'] !== null && $productId === null)) {
            throw new DraftFailure('unknown_reference', 'GPT вибрав товар або модель без підтвердженого джерела.');
        }
        if ($draft['size'] !== null && ($seenOffers[$productId]['requested_size'] ?? null) !== $draft['size']
            && ! in_array($draft['size'], array_column($seenOffers[$productId]['variants'] ?? [], 'size'), true)) {
            throw new DraftFailure('unknown_size', 'GPT вибрав розмір без підтвердження в каталозі.');
        }

        // Повторне читання захищає від зміни ціни, залишку чи розмітки під час генерації.
        $group = $groupId !== null ? $this->catalog->describe($groupId) : null;
        if ($group && array_diff($productIds, array_column($group['products'], 'id')) !== []) {
            throw new DraftFailure('wrong_product', 'Вибраний товар не належить до цієї модельної лінійки.');
        }
        $offers = $this->catalog->offers($productIds, $productId, $draft['size']);
        $offer = $productId !== null ? $offers[$productId] : null;
        $photos = [];
        $knownPhotos = array_column($seenGroups[$groupId]['photos'] ?? [], 'id');
        $currentPhotos = array_column($group['photos'] ?? [], null, 'id');
        foreach ($draft['photo_ids'] as $id) {
            $photo = $currentPhotos[$id] ?? null;
            if ($group === null || ! in_array($id, $knownPhotos, true) || ! $photo
                || ($productId !== null && ($photo['kind'] !== 'color' || $photo['product_ids'] !== [$productId]))) {
                throw new DraftFailure('wrong_photo', 'Фото не відповідає підтвердженій моделі або вибраному кольору.');
            }
            $photos[] = $photo;
        }
        $text = trim($draft['message']);
        if ($offer) {
            if (! $offer['available']) {
                $text = 'Вибраний варіант зараз недоступний. Можу допомогти підібрати інший.';
            }
        }
        foreach ($offers as $currentOffer) {
            $text .= "\n\n".$this->offerText($currentOffer);
        }

        return [
            'action' => $draft['action'], 'message' => $text, 'model_text' => trim($draft['message']),
            'group' => $group ? ['id' => $group['id'], 'name' => $group['name']] : null,
            'offer' => $offer, 'offers' => array_values($offers), 'photos' => $photos, 'needs_review' => true, 'mode' => 'drafts',
            'prepared_at' => now()->toIso8601String(),
        ];
    }

    private function offerText(array $offer): string
    {
        $lines = [$offer['title'].($offer['color'] ? ' · '.$offer['color'] : '')];
        $lines[] = $offer['sale_price'] !== null && $offer['currency']
            ? 'Ціна: '.$offer['sale_price'].' '.$offer['currency']
            : 'Ціну потрібно уточнити в менеджера.';
        if ($offer['sku']) {
            $lines[] = 'Артикул: '.$offer['sku'];
        }
        if ($offer['has_variants']) {
            if ($offer['requested_size'] !== null) {
                $lines[] = 'Розмір '.$offer['requested_size'].': '.($offer['available'] ? 'є в наявності' : 'немає в наявності');
            } else {
                $sizes = array_column(array_filter($offer['variants'], fn ($v) => $v['available']), 'size');
                $sizes = array_values(array_filter($sizes, fn ($s) => $s !== null && $s !== ''));
                $lines[] = $sizes ? 'Наявні розміри: '.implode(', ', array_unique($sizes)) : 'Наявні розміри потрібно уточнити.';
            }
        } else {
            $lines[] = $offer['available'] ? 'Є в наявності.' : 'Немає в наявності.';
        }

        return implode("\n", $lines);
    }
}
