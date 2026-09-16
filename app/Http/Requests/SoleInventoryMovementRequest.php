<?php

namespace App\Http\Requests;

use App\Services\Inventory\SoleInventoryCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SoleInventoryMovementRequest extends FormRequest
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
            'request_key' => ['required', 'uuid'],
            'size' => ['required', Rule::in($sizes)],
            'kind' => ['required', Rule::in(['receipt', 'adjustment'])],
            'quantity' => ['required', 'integer', 'not_in:0', 'min:'.($this->input('kind') === 'receipt' ? '1' : '-10000000'), 'max:10000000'],
            'movement_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now()->toDateString()],
            'note' => ['nullable', 'required_if:kind,adjustment', 'string', 'max:500'],
        ];
    }
}
