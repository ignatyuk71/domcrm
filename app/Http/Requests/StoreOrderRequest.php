<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Спільна валідація створення/оновлення замовлення (раніше дублювалась у
 * OrderController::store і ::update). RBAC уже гейтиться middleware role:owner,operator.
 */
class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer.first_name' => ['nullable', 'string', 'max:255'],
            'customer.last_name' => ['nullable', 'string', 'max:255'],
            'customer.phone' => ['nullable', 'string', 'max:32'],
            'customer.email' => ['nullable', 'string', 'max:255'],

            'order.source' => ['nullable', 'string', 'max:32'],
            'order.source_id' => ['nullable', 'integer', 'exists:order_sources,id'],
            'order.status' => ['required', 'string', 'max:32'],
            'order.payment_status' => ['required', 'string', 'max:32'],
            'order.currency' => ['required', 'string', 'max:3'],
            'order.comment_internal' => ['nullable', 'string'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.sku' => ['nullable', 'string', 'max:64'],
            'items.*.title' => ['nullable', 'string', 'max:255'],
            'items.*.size' => ['nullable', 'string', 'max:64'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],

            'payment.method' => ['required', 'string', 'max:32'],
            'payment.prepay_amount' => ['nullable', 'numeric', 'min:0'],
            'payment.currency' => ['required', 'string', 'max:3'],

            'delivery.carrier' => ['nullable', 'string', 'max:32'],
            'delivery.delivery_type' => ['required', 'string', 'max:32'],
            'delivery.payer' => ['nullable', 'string', 'max:32'],
            'delivery.ttn' => ['nullable', 'string', 'max:64'],
            'delivery.city_ref' => ['nullable', 'string', 'max:64'],
            'delivery.settlement_ref' => ['nullable', 'string', 'max:64'],
            'delivery.city_name' => ['nullable', 'string', 'max:255'],
            'delivery.warehouse_ref' => ['nullable', 'string', 'max:64'],
            'delivery.warehouse_name' => ['nullable', 'string', 'max:255'],
            'delivery.street_name' => ['nullable', 'string', 'max:255'],
            'delivery.street_ref' => ['nullable', 'string', 'max:64'],
            'delivery.address_ref' => ['nullable', 'string', 'max:64'],
            'delivery.building' => ['nullable', 'string', 'max:64'],
            'delivery.apartment' => ['nullable', 'string', 'max:64'],
            'delivery.address_note' => ['nullable', 'string', 'max:255'],
            'delivery.recipient_name' => ['nullable', 'string', 'max:255'],
            'delivery.recipient_phone' => ['nullable', 'string', 'max:64'],

            'tag_ids' => ['array'],
            'tag_ids.*' => ['nullable'],
        ];
    }

    /** Доставку має бути ОБРАНО зі списку (місто/відділення з ref), а не просто вписано. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $delivery = (array) $this->input('delivery', []);

            if (trim((string) ($delivery['city_name'] ?? '')) !== '' && empty($delivery['city_ref'])) {
                $v->errors()->add('delivery.city_name', 'Оберіть місто зі списку підказок.');
            }

            if (($delivery['delivery_type'] ?? 'warehouse') !== 'courier'
                && trim((string) ($delivery['warehouse_name'] ?? '')) !== ''
                && empty($delivery['warehouse_ref'])) {
                $v->errors()->add('delivery.warehouse_name', 'Оберіть відділення або поштомат зі списку.');
            }
        });
    }
}
