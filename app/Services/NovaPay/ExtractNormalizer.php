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
            // Невідомий статус блокує весь період, щоб не представити неповну суму як підтверджену.
            $mapped = config('novapay.operation_statuses', [])[$status] ?? null;
            if (! in_array($mapped, ['posted', 'pending', 'cancelled'], true)) {
                throw new NovaPayException('extract_contract', 'Потрібна перевірка нового статусу операції NovaPay.');
            }
            $incoming = $credit === $account->iban;
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
            ];
        }
        if (count(array_unique(array_column($records, 'provider_id'))) !== count($records)) {
            throw new NovaPayException('extract_contract', 'Виписка NovaPay містить повторні ID. Період потребує перевірки.');
        }

        return $records;
    }
}
