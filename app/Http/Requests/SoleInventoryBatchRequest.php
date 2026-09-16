<?php

namespace App\Http\Requests;

use App\Services\Inventory\SoleInventoryCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SoleInventoryBatchRequest extends FormRequest
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
            'request_key' => [$this->isMethod('post') ? 'required' : 'prohibited', 'uuid'],
            'version' => [$this->isMethod('put') ? 'required' : 'prohibited', 'integer', 'min:1'],
            'received_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:2020-01-01', 'before_or_equal:'.now()->toDateString()],
            'quantities' => ['required', 'array', 'size:'.count($sizes)],
            'quantities.*' => ['array:size,quantity'],
            'quantities.*.size' => ['required', 'distinct', Rule::in($sizes)],
            'quantities.*.quantity' => ['required', 'integer', 'min:0', 'max:10000000'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $validator->errors()->any() && collect($this->input('quantities', []))->sum('quantity') <= 0) {
                $validator->errors()->add('quantities', 'Вкажіть кількість отриманих пар хоча б для одного розміру.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'received_on.required' => 'Вкажіть дату приїзду партії.',
            'received_on.date_format' => 'Вкажіть коректну дату приїзду.',
            'received_on.before_or_equal' => 'Додавайте лише партії, які вже приїхали.',
            'received_on.after_or_equal' => 'Дата приїзду має бути не раніше 2020 року.',
            'quantities.*.quantity.required' => 'Вкажіть отриману кількість кожного розміру; якщо його не було в партії — 0.',
            'quantities.*.quantity.integer' => 'Кількість пар має бути цілим числом.',
            'quantities.*.quantity.min' => 'Отримана кількість не може бути від’ємною.',
            'quantities.*.quantity.max' => 'Перевірте кількість: максимум 10 000 000 пар на розмір.',
            'quantities.size' => 'Передайте кількості всіх розмірів категорії.',
            'quantities.*.size.in' => 'Цей розмір не належить до категорії.',
            'quantities.*.size.distinct' => 'Один розмір не може повторюватися в партії.',
        ];
    }
}
