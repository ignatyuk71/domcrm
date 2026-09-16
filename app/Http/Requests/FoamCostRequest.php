<?php

namespace App\Http\Requests;

class FoamCostRequest extends CardboardCostBatchRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $values = [];
        foreach (['sheet_price_usd', 'usd_rate'] as $field) {
            if (is_string($this->input($field))) {
                $values[$field] = str_replace(',', '.', trim($this->input($field)));
            }
        }
        $this->merge($values);
    }

    public function rules(): array
    {
        return array_replace(parent::rules(), [
            // Відомо ціну одного листа; кількість закуплених листів тут не потрібна.
            'quantity' => ['prohibited'], 'goods_uah' => ['prohibited'],
            'sheet_price_usd' => ['required', 'numeric', 'min:0', 'max:1000', 'regex:/^\d+(?:\.\d{1,2})?$/D'],
            'usd_rate' => ['required', 'numeric', 'min:0.0001', 'max:1000', 'regex:/^\d+(?:\.\d{1,4})?$/D'],
        ]);
    }

    public function attributes(): array
    {
        return array_replace(parent::attributes(), [
            'name' => 'Назва розрахунку', 'purchased_on' => 'Дата ціни', 'sheet_price_usd' => 'Ціна одного листа',
            'usd_rate' => 'Курс долара', 'shipping_uah' => 'Доставка на один лист',
            'blank_length_cm' => 'Довжина вставки', 'blank_width_cm' => 'Ширина вставки',
        ]);
    }

    public function messages(): array
    {
        return parent::messages() + ['usd_rate.regex' => 'Курс долара: до 4 знаків після коми.'];
    }
}
