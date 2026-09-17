<?php

namespace App\Http\Requests;

use App\Services\Costs\FurCostCalculator;
use App\Services\Costs\SoleCostCalculator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class FurCostRequest extends SoleCostBatchRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $values = [];
        foreach ([...array_keys(FurCostCalculator::GEOMETRY), 'goods_uah'] as $field) {
            if (is_string($this->input($field))) {
                $values[$field] = str_replace(',', '.', trim($this->input($field)));
            }
        }
        if ($this->input('ukraine_shipping_uah') === '') {
            $values['ukraine_shipping_uah'] = null;
        }
        $this->merge($values);
    }

    public function rules(): array
    {
        $rules = array_replace(parent::rules(), [
            'quantity' => ['prohibited'],
            'upper_shipping_usd' => ['prohibited'],
            'purchase_source' => ['sometimes', 'required', Rule::in(['china', 'ukraine'])],
            'goods_uah' => ['prohibited'],
            'layout' => ['prohibited'], 'total_pieces' => ['prohibited'], 'pairs' => ['prohibited'],
            'length_unit' => ['required', Rule::in(['yard', 'metre'])],
            'ukraine_shipping_uah' => ['nullable', 'numeric', 'min:0', 'max:1000000', 'regex:/^\d+(?:\.\d{1,2})?$/D'],
        ]);
        if ($this->input('purchase_source', 'china') === 'ukraine') {
            foreach (array_diff(array_keys(SoleCostCalculator::FIELDS), FurCostCalculator::LOCAL_FIELDS) as $field) {
                $rules[$field] = ['prohibited'];
            }
            $rules['goods_uah'] = ['required', 'numeric', 'min:0', 'max:1000000', 'regex:/^\d+(?:\.\d{1,2})?$/D'];
        }
        foreach (FurCostCalculator::GEOMETRY as $field => $precision) {
            $rules[$field] = [...($field === 'cut_length_cm' ? ['sometimes'] : []), 'required', 'numeric', 'min:'.($field === 'fabric_length' ? '0.0001' : '0.01'),
                'max:'.($field === 'fabric_length' ? '1000000' : '1000'), 'regex:/^\d+(?:\.\d{1,'.$precision.'})?$/D'];
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
            $calculator = app(FurCostCalculator::class);
            $lengthCm = (float) $data['fabric_length'] * ($data['length_unit'] === 'yard' ? 91.44 : 100);
            $cutLength = (float) ($data['cut_length_cm'] ?? 100);
            if (max((float) $data['top_width_cm'], (float) $data['bottom_width_cm']) > min($lengthCm, $cutLength)) {
                $validator->errors()->add($lengthCm < $cutLength ? 'fabric_length' : 'cut_length_cm', 'Довжина робочого відрізу має вміщати більшу основу трапеції.');
            }
            if ((float) $data['height_cm'] > (float) $data['fabric_width_cm']) {
                $validator->errors()->add('fabric_width_cm', 'Ширина полотна має вміщати хоча б один ряд за висотою трапеції.');
            }
            if ($validator->errors()->isEmpty()) {
                if ($calculator->layout($data)['pairs'] === 0) {
                    $validator->errors()->add('fabric_length', 'З цього полотна не виходить двох цілих деталей на одну пару. Перевірте розміри розкрою.');

                    return;
                }
                // Не допускаємо переповнення спільної DECIMAL(16,6) навіть на граничних сумах.
                if ($calculator->calculate(1, $calculator->normalize($data))['unit_cost_uah'] >= 10000000000) {
                    $validator->errors()->add(($data['purchase_source'] ?? 'china') === 'ukraine' ? 'goods_uah' : 'goods_cny', 'Завелика вартість на пару. Перевірте суми, курси та розміри полотна.');
                }
            }
        }];
    }

    public function attributes(): array
    {
        return array_replace(parent::attributes(), [
            'purchase_source' => 'Де купуєте хутро', 'goods_uah' => 'Сума за всю партію хутра',
            'goods_cny' => 'Сума лише за хутро', 'fabric_length' => 'Довжина хутра', 'length_unit' => 'Одиниця довжини',
            'fabric_width_cm' => 'Ширина полотна', 'cut_length_cm' => 'Довжина робочого відрізу', 'top_width_cm' => 'Верхня основа', 'bottom_width_cm' => 'Нижня основа', 'height_cm' => 'Висота деталі',
        ]);
    }

    public function messages(): array
    {
        return array_replace(parent::messages(), [
            'fabric_length.regex' => 'Довжина хутра: до 4 знаків після коми.',
            'length_unit.in' => 'Виберіть ярди або погонні метри.',
            'purchase_source.in' => 'Виберіть закупівлю в Китаї або Україні.',
            'prohibited' => 'Поле «:attribute» не використовується для цього розрахунку.',
            'regex' => 'Перевірте поле «:attribute»: суми й сантиметри — до 2 знаків, курси — до 4.',
        ]);
    }
}
