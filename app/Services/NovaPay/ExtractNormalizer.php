<?php

namespace App\Services\NovaPay;

use App\Models\NovaPayAccount;
use Carbon\CarbonImmutable;

class ExtractNormalizer
{
    public function normalize(string $payload, NovaPayAccount $account, string $from, string $to): array
    {
        $xml = Xml::parse($payload);
        if ($xml->documentElement?->localName !== 'Payments') {
            throw new NovaPayException('extract_contract', 'Формат виписки NovaPay відрізняється від перевіреного контракту.');
        }
        $records = [];
        foreach ($xml->documentElement->childNodes as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }
            if ($node->localName !== 'Docs') {
                throw new NovaPayException('extract_contract', 'Відповідь платежів містить непідтверджені секції. Період не імпортовано.');
            }
            $id = Xml::value($node, 'ID');
            $status = Xml::value($node, 'StatusDocument');
            if ($status === '' || mb_strlen($status) > 64 || preg_match('/[\x00-\x1F\x7F]/u', $status)) {
                throw new NovaPayException('extract_contract', 'Операція NovaPay має порожній або некоректний статус.');
            }
            // DayDate є і в надходжень, і у проведених списань із порожньою PayDate.
            $date = Xml::value($node, 'DayDate');
            $credit = strtoupper(Xml::value($node, 'CreditCodeIBAN'));
            $debit = strtoupper(Xml::value($node, 'DebitCodeIBAN'));
            if ($id === '' || strlen($id) > 128 || $date === '' || $credit === $debit
                || ! in_array($account->iban, [$credit, $debit], true)
                || $node->getAttribute('CurrencyTag') !== $account->currency) {
                throw new NovaPayException('extract_contract', 'У виписці немає однозначного ID, дати проведення або реквізитів рахунку.');
            }
            try {
                $booked = CarbonImmutable::createFromFormat('!d.m.Y', $date, $account->timezone);
                if (! $booked || $booked->format('d.m.Y') !== $date) {
                    throw new \RuntimeException;
                }
            } catch (\Throwable) {
                throw new NovaPayException('extract_contract', 'Некоректна дата проведення у виписці NovaPay.');
            }
            $amount = Money::minor($node->getAttribute('Amount'));
            if ($amount < 0 || $booked->toDateString() < $from || $booked->toDateString() > $to) {
                throw new NovaPayException('extract_contract', 'Операція NovaPay має некоректну суму або виходить за запитаний період.');
            }
            // Нові статуси зберігаємо дослівно, але не вважаємо підтвердженим рухом коштів.
            $mapped = config('novapay.operation_statuses', [])[$status] ?? 'unknown';
            if (! in_array($mapped, ['posted', 'pending', 'cancelled', 'unknown'], true)) {
                throw new NovaPayException('extract_contract', 'Некоректне зіставлення статусу операції NovaPay.');
            }
            $incoming = $credit === $account->iban;
            $validIban = fn (string $value) => preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/D', $value) ? $value : null;
            $optionalText = static function (string $field, int $limit) use ($node): ?string {
                $value = Xml::value($node, $field);

                return $value === '' ? null : mb_substr($value, 0, $limit);
            };
            $counterpartyIban = $incoming ? $debit : $credit;
            // Реквізити контрагента необов'язкові: службовий номер не є IBAN і не скасовує рух коштів.
            if (! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/D', $counterpartyIban)) {
                $counterpartyIban = null;
            }
            $changed = Xml::value($node, 'Changed');
            $changedAt = null;
            if ($changed !== '') {
                try {
                    $changedAt = CarbonImmutable::createFromFormat('!d.m.Y H:i:s', $changed, $account->timezone);
                    if (! $changedAt || $changedAt->format('d.m.Y H:i:s') !== $changed) {
                        throw new \RuntimeException;
                    }
                } catch (\Throwable) {
                    throw new NovaPayException('extract_contract', 'Некоректний час зміни статусу платежу NovaPay.');
                }
            }
            $records[] = [
                'provider_id' => $id, 'booked_on' => $booked->toDateString(), 'direction' => $incoming ? 'in' : 'out',
                'amount_minor' => $amount, 'status' => $mapped, 'source_status' => $status,
                'counterparty' => Xml::value($node, $incoming ? 'DebitName' : 'CreditName'),
                'counterparty_iban' => $counterpartyIban ?: null,
                'status_changed_at' => $changedAt?->utc()->format('Y-m-d H:i:s'),
                'purpose' => Xml::value($node, 'Purpose'),
                // Необов'язкові реквізити не блокують імпорт руху коштів.
                'document_number' => $optionalText('Code', 128),
                'sender_name' => $optionalText('DebitName', 255),
                'sender_iban' => $validIban($debit),
                'sender_tax_code' => $optionalText('DebitStateCode', 32),
                'recipient_name' => $optionalText('CreditName', 255),
                'recipient_iban' => $validIban($credit),
                'recipient_tax_code' => $optionalText('CreditStateCode', 32),
            ];
        }
        if (count(array_unique(array_column($records, 'provider_id'))) !== count($records)) {
            throw new NovaPayException('extract_contract', 'Виписка NovaPay містить повторні ID. Період потребує перевірки.');
        }

        return $records;
    }
}
