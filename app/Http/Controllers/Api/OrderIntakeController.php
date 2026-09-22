<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessExternalOrder;
use App\Models\ExternalOrderRaw;
use App\Models\OrderSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderIntakeController extends Controller
{
    /**
     * Приймає замовлення із зовнішнього сайту: зберігає сирий payload,
     * ставить у чергу обробку, повертає підтвердження. Замовлення не губиться.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var OrderSource $source */
        $source = $request->attributes->get('external_source');

        $request->validate([
            'external_order_id' => ['nullable', function ($attribute, $value, $fail) {
                if ((! is_string($value) && ! is_int($value)) || strlen((string) $value) > 255) {
                    $fail('Номер замовлення має бути рядком або цілим числом до 255 символів.');
                }
            }],
            'payment' => ['sometimes', 'array'],
            'payment.method' => ['nullable', 'string', 'max:24'],
            'payment.provider' => ['nullable', 'string', 'max:64'],
            'payment.status' => ['nullable', Rule::in(['unpaid', 'prepayment', 'paid'])],
            'payment_status' => ['nullable', Rule::in(['unpaid', 'prepayment', 'paid'])],
            'payment.paid_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'payment.transaction_id' => ['nullable', 'string', 'max:255'],
            'payment.paid_at' => ['nullable', 'date'],
            'payment.currency' => ['nullable', 'string', 'size:3'],
        ]);
        $payload = $request->all();
        $externalOrderId = isset($payload['external_order_id']) && trim((string) $payload['external_order_id']) !== ''
            ? trim((string) $payload['external_order_id'])
            : null;

        [$raw, $duplicate] = DB::transaction(function () use ($source, $payload, $externalOrderId) {
            OrderSource::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            $existing = $externalOrderId !== null ? ExternalOrderRaw::query()
                ->where('source_id', $source->id)
                ->where('external_order_id', $externalOrderId)
                ->lockForUpdate()->first() : null;

            if ($existing) {
                // Оновлюємо лише оплату; товари, адресу й роботу менеджера не перезаписуємо.
                $updatedPayload = $existing->payload;
                if (isset($payload['payment'])) {
                    $updatedPayload['payment'] = array_replace($updatedPayload['payment'] ?? [], $payload['payment']);
                }
                if (isset($payload['payment_status']) && ! isset($payload['payment']['status'])) {
                    $updatedPayload['payment']['status'] = $payload['payment_status'];
                }
                // Черга може ще не обробити підтвердження: захищаємо також сам payload.
                $ranks = ['unpaid' => 0, 'prepayment' => 1, 'paid' => 2];
                $previousPayment = $existing->payload['payment'] ?? [];
                $nextPayment = $updatedPayload['payment'] ?? [];
                $previousRank = $ranks[$previousPayment['status'] ?? $existing->payload['payment_status'] ?? ''] ?? -1;
                $nextRank = $ranks[$nextPayment['status'] ?? $updatedPayload['payment_status'] ?? ''] ?? -1;
                $lowerAmount = isset($previousPayment['paid_amount'], $nextPayment['paid_amount'])
                    && (float) $nextPayment['paid_amount'] < (float) $previousPayment['paid_amount'];
                if ($existing->status !== ExternalOrderRaw::STATUS_FAILED
                    && ($nextRank < $previousRank || ($nextRank === $previousRank && $lowerAmount))) {
                    $updatedPayload = $existing->payload;
                }
                $duplicate = $updatedPayload === $existing->payload
                    && $existing->status === ExternalOrderRaw::STATUS_PROCESSED;
                if (! $duplicate) {
                    $existing->update([
                        'payload' => $updatedPayload,
                        'status' => ExternalOrderRaw::STATUS_RECEIVED,
                        'received_at' => now(),
                        'processed_at' => null,
                        'error' => null,
                    ]);
                }

                return [$existing, $duplicate];
            }

            if (empty($payload['items']) || ! is_array($payload['items'])) {
                throw ValidationException::withMessages(['items' => 'Для нового замовлення потрібні товари. Спочатку надішліть повне замовлення.']);
            }

            return [ExternalOrderRaw::create([
                'source_id' => $source->id,
                'external_order_id' => $externalOrderId,
                'adapter' => $source->adapter,
                'payload' => $payload,
                'status' => ExternalOrderRaw::STATUS_RECEIVED,
                'received_at' => now(),
            ]), false];
        });

        if ($duplicate) {
            return response()->json([
                'accepted' => true,
                'duplicate' => true,
                'raw_id' => $raw->id,
                'status' => $raw->status,
                'order_id' => $raw->order_id,
            ]);
        }

        // Обробка в черзі. При sync-черзі виконається одразу; будь-яку помилку
        // обробника ловимо (статус уже збережено в раві), щоб сайт отримав коректну відповідь.
        try {
            ProcessExternalOrder::dispatch($raw->id);
        } catch (Throwable $e) {
            // no-op: статус failed уже у external_orders_raw
        }

        $raw->refresh();

        return response()->json([
            'accepted' => true,
            'raw_id' => $raw->id,
            'status' => $raw->status,
            'order_id' => $raw->order_id,
            'needs_review' => optional($raw->order)->needs_review,
        ], 202);
    }
}
