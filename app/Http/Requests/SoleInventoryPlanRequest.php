<?php

namespace App\Http\Requests;

use App\Services\Inventory\SoleInventoryCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SoleInventoryPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isOwner() ?? false;
    }

    public function rules(): array
    {
        $catalog = app(SoleInventoryCatalog::class);
        $catalog->category((int) $this->route('category'));

        return [
            'version' => ['required', 'integer', 'min:0'],
            'opening_date' => ['prohibited'],
            'opening_balances' => ['prohibited'],
            'lead_time_days' => ['present', 'nullable', 'integer', 'min:1', 'max:730'],
            'safety_days' => ['required', 'integer', 'min:0', 'max:365'],
            'lookback_days' => ['required', Rule::in([0, 30, 60, 90])],
        ];
    }

    public function attributes(): array
    {
        return ['lead_time_days' => 'термін поставки', 'safety_days' => 'запас часу'];
    }

    public function messages(): array
    {
        return ['opening_date.prohibited' => 'Формат сторінки змінився. Оновіть її та внесіть отриману партію.',
            'opening_balances.prohibited' => 'Залишки розраховуються автоматично. Оновіть сторінку та внесіть отриману партію.',
            'lead_time_days.min' => 'Термін поставки має бути від 1 дня.',
            'lead_time_days.max' => 'Термін поставки має бути не більшим за 730 днів.',
            'safety_days.min' => 'Запас часу не може бути від’ємним.',
            'safety_days.max' => 'Запас часу має бути не більшим за 365 днів.'];
    }
}
