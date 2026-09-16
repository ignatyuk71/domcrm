<?php

namespace App\Http\Requests;

use App\Services\Costs\SoleCostCalculator;
use Illuminate\Foundation\Http\FormRequest;

class SoleCostBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isOwner() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (array_keys(SoleCostCalculator::FIELDS) as $field) {
            if (is_string($this->input($field))) {
                $values[$field] = str_replace(',', '.', trim($this->input($field)));
            }
        }
        $this->merge($values);
    }

    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:160'],
            'purchased_on' => ['nullable', 'date_format:Y-m-d'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10000000'],
            'note' => ['nullable', 'string', 'max:2000'],
            'request_key' => $this->isMethod('POST') ? ['required', 'uuid'] : ['prohibited'],
            'version' => $this->isMethod('PUT') ? ['required', 'integer', 'min:1'] : ['prohibited'],
            // Клієнт надсилає лише вихідні дані, підсумок завжди перераховує сервер.
            'total_uah' => ['prohibited'], 'unit_cost_uah' => ['prohibited'], 'component' => ['prohibited'],
        ];
        foreach (SoleCostCalculator::FIELDS as $field => $precision) {
            $max = str_ends_with($field, '_rate') ? 1000 : ($field === 'commission_percent' ? 100 : 1000000);
            $min = str_ends_with($field, '_rate') ? '0.0001' : 0;
            $rules[$field] = ['required', 'numeric', 'min:'.$min, 'max:'.$max, 'regex:/^\d+(?:\.\d{1,'.$precision.'})?$/D'];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'name' => 'Назва партії', 'quantity' => 'Кількість пар', 'purchased_on' => 'Дата рахунку',
            'goods_cny' => 'Сума за підошву', 'china_shipping_cny' => 'Доставка по Китаю',
            'commission_percent' => 'Комісія', 'international_shipping_usd' => 'Міжнародна доставка',
            'ukraine_shipping_uah' => 'Доставка по Україні', 'other_costs_uah' => 'Інші витрати',
            'cny_rate' => 'Курс юаня', 'usd_rate' => 'Курс долара', 'note' => 'Примітка',
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Заповніть поле «:attribute».', 'numeric' => 'Поле «:attribute» має містити число.',
            'integer' => 'Поле «:attribute» має містити ціле число.',
            'min' => 'Поле «:attribute»: мінімальне значення — :min.',
            'max' => 'Перевищено допустиме значення поля «:attribute» (:max).',
            'regex' => 'Перевірте число у полі «:attribute»: суми — до 2 знаків після коми, курси — до 4.',
            'date_format' => 'Вкажіть коректну дату рахунку.',
        ];
    }
}
