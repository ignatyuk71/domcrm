<?php

namespace App\Http\Requests;

use App\Services\Costs\TapeCostCalculator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class TapeCostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isOwner() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (TapeCostCalculator::FIELDS as $field => $precision) {
            if (is_string($this->input($field))) {
                $value = str_replace(',', '.', trim($this->input($field)));
                $values[$field] = $field === 'shipping_uah' && $value === '' ? null : $value;
            }
        }
        $this->merge($values);
    }

    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:160'], 'purchased_on' => ['nullable', 'date_format:Y-m-d'], 'note' => ['nullable', 'string', 'max:2000'],
            'request_key' => $this->isMethod('POST') ? ['required', 'uuid'] : ['prohibited'],
            'version' => $this->isMethod('PUT') ? ['required', 'integer', 'min:1'] : ['prohibited'],
        ];
        foreach (['quantity', 'component', 'total_uah', 'unit_cost_uah', 'metre_cost_uah', 'slipper_cost_uah', 'pair_length_m', 'whole_pairs', 'remaining_length_m', 'breakdown'] as $field) {
            $rules[$field] = ['prohibited'];
        }
        foreach (TapeCostCalculator::FIELDS as $field => $precision) {
            $min = $field === 'length_m' ? '0.0001' : ($field === 'per_slipper_cm' ? '0.01' : '0');
            $rules[$field] = [$field === 'shipping_uah' ? 'nullable' : 'required', 'numeric', 'min:'.$min,
                'max:'.(str_ends_with($field, '_cm') ? '1000' : '1000000'), 'regex:/^\d+(?:\.\d{1,'.$precision.'})?$/D'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $calculator = app(TapeCostCalculator::class);
            $calculation = $calculator->calculate(1, $calculator->normalize($validator->getData()));
            if ($calculation['whole_pairs'] === 0) {
                $validator->errors()->add('length_m', 'Довжини партії має вистачати на одну пару з урахуванням запасу. Перевірте метри та сантиметри.');
            }
        }];
    }

    public function attributes(): array
    {
        return ['name' => 'Назва партії', 'purchased_on' => 'Дата рахунку', 'note' => 'Примітка', 'length_m' => 'Кількість стрічки у метрах',
            'goods_uah' => 'Викуп стрічки з комісією', 'shipping_uah' => 'Доставка всієї партії', 'per_slipper_cm' => 'Стрічка на один капець', 'allowance_cm' => 'Запас на один капець'];
    }

    public function messages(): array
    {
        return ['required' => 'Заповніть поле «:attribute».', 'numeric' => 'Поле «:attribute» має містити число.',
            'min' => 'Поле «:attribute»: мінімум — :min.', 'max' => 'Поле «:attribute»: максимум — :max.',
            'regex' => 'Поле «:attribute»: суми й сантиметри — до 2 знаків, метри — до 4 знаків після коми.',
            'date_format' => 'Вкажіть коректну дату рахунку.', 'prohibited' => 'Поле «:attribute» визначає сервер, не передавайте його.'];
    }
}
