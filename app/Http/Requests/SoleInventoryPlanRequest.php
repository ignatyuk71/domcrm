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
        $sizes = $catalog->sizes($catalog->category((int) $this->route('category')));

        return [
            'version' => ['required', 'integer', 'min:0'],
            'opening_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2020-01-01', 'before_or_equal:'.now()->toDateString()],
            'opening_balances' => ['required', 'array', 'size:'.count($sizes)],
            'opening_balances.*' => ['array:size,quantity'],
            'opening_balances.*.size' => ['required', 'distinct', Rule::in($sizes)],
            'opening_balances.*.quantity' => ['present', 'nullable', 'integer', 'min:0', 'max:10000000'],
            'lead_time_days' => ['present', 'nullable', 'integer', 'min:1', 'max:730'],
            'safety_days' => ['required', 'integer', 'min:0', 'max:365'],
            'lookback_days' => ['required', Rule::in([30, 60, 90])],
        ];
    }

    public function attributes(): array
    {
        return ['opening_date' => 'дата початкового залишку', 'opening_balances.*.quantity' => 'кількість пар', 'lead_time_days' => 'термін поставки', 'safety_days' => 'запас часу'];
    }
}
