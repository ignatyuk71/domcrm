<?php

namespace App\Http\Requests;

use App\Services\Costs\LaminateCostCalculator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class LaminateCostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isOwner() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (LaminateCostCalculator::FIELDS as $field => $precision) {
            $value = $this->input($field);
            if (is_string($value)) {
                $value = str_replace(',', '.', trim($value));
                $values[$field] = in_array($field, LaminateCostCalculator::SHIPPING, true) && $value === '' ? null : $value;
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
            'quantity' => ['prohibited'], 'total_uah' => ['prohibited'], 'unit_cost_uah' => ['prohibited'], 'component' => ['prohibited'],
            'insole_pair_cost_uah' => ['prohibited'], 'upper_pair_cost_uah' => ['prohibited'], 'insole_layout' => ['prohibited'], 'upper_layout' => ['prohibited'],
        ];
        foreach (LaminateCostCalculator::FIELDS as $field => $precision) {
            $dimension = str_ends_with($field, '_cm');
            $rateOrLength = in_array($field, ['usd_rate', 'web_roll_length_m'], true);
            $max = $dimension || in_array($field, ['usd_rate', 'foam_sheet_price_usd'], true) ? 1000 : 1000000;
            $rules[$field] = [in_array($field, LaminateCostCalculator::SHIPPING, true) ? 'nullable' : 'required', 'numeric',
                'min:'.($dimension ? '0.01' : ($rateOrLength ? '0.0001' : '0')), 'max:'.$max, 'regex:/^\d+(?:\.\d{1,'.$precision.'})?$/D'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $calculator = app(LaminateCostCalculator::class);
            $data = $validator->getData();
            if (! $calculator->geometryFits($data)) {
                $validator->errors()->add('insole_length_cm', 'Обидві заготовки мають поміщатися у плюш, павутинку та лист поролону. Перевірте розміри й одиниці.');

                return;
            }
            $calculation = $calculator->calculate(1, $calculator->normalize($data));
            foreach (['insole' => 'insole_length_cm', 'upper' => 'upper_top_cm'] as $part => $field) {
                if ($calculation[$part.'_layout']['pairs'] === 0) {
                    $validator->errors()->add($field, 'З відрізу 100 см × ширина полотна має виходити хоча б 2 цілі деталі цього типу. Перевірте напрям рядів і розміри.');
                }
            }
            if ($calculation['total_uah'] >= 1000000000000 || $calculation['insole_pair_cost_uah'] >= 10000000000 || $calculation['upper_pair_cost_uah'] >= 10000000000) {
                $validator->errors()->add('plush_price_metre_uah', 'Завелика вартість. Перевірте ціни та розміри матеріалів.');
            }
        }];
    }

    public function attributes(): array
    {
        return ['name' => 'Назва розрахунку', 'purchased_on' => 'Дата розцінки', 'note' => 'Примітка', 'cut_width_cm' => 'Ширина склеєного полотна на розкрої',
            'plush_price_metre_uah' => 'Ціна плюшу за погонний метр', 'plush_width_cm' => 'Ширина плюшу', 'plush_shipping_metre_uah' => 'Доставка плюшу на погонний метр',
            'web_roll_price_uah' => 'Ціна рулону павутинки', 'web_roll_length_m' => 'Довжина рулону павутинки', 'web_width_cm' => 'Ширина павутинки', 'web_shipping_roll_uah' => 'Доставка рулону павутинки',
            'foam_sheet_price_usd' => 'Ціна листа поролону', 'usd_rate' => 'Курс долара', 'foam_sheet_length_cm' => 'Довжина листа поролону', 'foam_sheet_width_cm' => 'Ширина листа поролону', 'foam_shipping_sheet_uah' => 'Доставка на лист поролону',
            'insole_length_cm' => 'Довжина заготовки устілки', 'insole_width_cm' => 'Ширина заготовки устілки', 'upper_top_cm' => 'Верхня основа', 'upper_bottom_cm' => 'Нижня основа', 'upper_height_cm' => 'Висота внутрішнього верху'];
    }

    public function messages(): array
    {
        return ['required' => 'Заповніть поле «:attribute».', 'numeric' => 'Поле «:attribute» має містити число.', 'min' => 'Поле «:attribute»: мінімум — :min.',
            'max' => 'Поле «:attribute»: максимум — :max.', 'regex' => 'Перевірте поле «:attribute»: ціни й сантиметри — до 2 знаків, курс і довжина рулону — до 4.',
            'date_format' => 'Вкажіть коректну дату розцінки.', 'prohibited' => 'Поле «:attribute» не використовується для цього розрахунку.'];
    }
}
