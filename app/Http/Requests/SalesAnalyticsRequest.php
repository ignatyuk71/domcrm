<?php

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SalesAnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'currency' => ['nullable', 'string', 'size:3'],
            'scope' => ['nullable', Rule::in(['valid', 'completed', 'all'])],
            'sale_type' => ['nullable', Rule::in(['retail', 'wholesale'])],
            'source_id' => ['nullable', 'integer', 'exists:order_sources,id'],
            'manager_id' => ['nullable', 'integer', 'exists:users,id'],
            'status_id' => ['nullable', 'integer', 'exists:statuses,id'],
            'payment_status' => ['nullable', Rule::in(['unpaid', 'prepayment', 'paid', 'refund'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'fresh' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->filled('date_from') || ! $this->filled('date_to')) {
                return;
            }

            $days = Carbon::parse($this->string('date_from'))->diffInDays(Carbon::parse($this->string('date_to')));
            if ($days > 730) {
                $validator->errors()->add('date_to', 'Максимальний період звіту — 731 день.');
            }
        });
    }
}
