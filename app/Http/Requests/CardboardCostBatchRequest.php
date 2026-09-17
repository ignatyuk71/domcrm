<?php

namespace App\Http\Requests;

use App\Services\Costs\CardboardCostCalculator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CardboardCostBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isOwner() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (CardboardCostCalculator::FIELDS as $field) {
            if (is_string($this->input($field))) {
                $values[$field] = str_replace(',', '.', trim($this->input($field)));
            }
        }
        if (($values['shipping_uah'] ?? null) === '') {
            $values['shipping_uah'] = null;
        }
        $this->merge($values);
    }

    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:160'], 'purchased_on' => ['nullable', 'date_format:Y-m-d'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10000000'], 'note' => ['nullable', 'string', 'max:2000'],
            'request_key' => $this->isMethod('POST') ? ['required', 'uuid'] : ['prohibited'],
            'version' => $this->isMethod('PUT') ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'total_uah' => ['prohibited'], 'unit_cost_uah' => ['prohibited'], 'component' => ['prohibited'],
            'layout' => ['prohibited'], 'method' => ['prohibited'],
        ];
        foreach (CardboardCostCalculator::FIELDS as $field) {
            $dimension = str_ends_with($field, '_cm');
            $rules[$field] = [$field === 'shipping_uah' ? 'nullable' : 'required', 'numeric',
                'min:'.($dimension ? '0.01' : '0'), 'max:'.($dimension ? '1000' : '1000000'), 'regex:/^\d+(?:\.\d{1,2})?$/D'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $data = $validator->getData();
            $sheet = [(float) $data['sheet_length_cm'], (float) $data['sheet_width_cm']];
            $blank = [(float) $data['blank_length_cm'], (float) $data['blank_width_cm']];
            sort($sheet);
            sort($blank);
            if ($blank[0] > $sheet[0] || $blank[1] > $sheet[1]) {
                $validator->errors()->add('blank_length_cm', 'Заготовка має поміщатися в лист, у тому числі після повороту. Перевірте розміри у сантиметрах.');

                return;
            }
            if ($this->requiresWholePairs() && app(CardboardCostCalculator::class)->layout($data)['pairs'] === 0) {
                $validator->errors()->add('blank_length_cm', 'Із одного листа має виходити хоча б 2 цілі заготовки. Перевірте розміри листа й деталі.');
            }
        }];
    }

    protected function requiresWholePairs(): bool
    {
        return true;
    }

    public function attributes(): array
    {
        return ['name' => 'Назва партії', 'purchased_on' => 'Дата рахунку', 'quantity' => 'Кількість листів',
            'goods_uah' => 'Сума за картон', 'shipping_uah' => 'Доставка картону', 'note' => 'Примітка',
            'sheet_length_cm' => 'Довжина листа', 'sheet_width_cm' => 'Ширина листа',
            'blank_length_cm' => 'Довжина заготовки', 'blank_width_cm' => 'Ширина заготовки'];
    }

    public function messages(): array
    {
        return ['required' => 'Заповніть поле «:attribute».', 'numeric' => 'Поле «:attribute» має містити число.',
            'integer' => 'Поле «:attribute» має містити ціле число.', 'min' => 'Поле «:attribute»: мінімум — :min.',
            'max' => 'Поле «:attribute»: максимум — :max.', 'regex' => 'Поле «:attribute»: до 2 знаків після коми.',
            'date_format' => 'Вкажіть коректну дату рахунку.'];
    }
}
