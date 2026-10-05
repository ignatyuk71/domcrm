<?php

namespace App\Services\NovaPay;

use App\Models\NovaPayAccount;
use Carbon\CarbonImmutable;

class ExtractNormalizer
{
    public function normalize(string $payload, NovaPayAccount $account, string $from, string $to): array
    {
        $xml = Xml::parse($payload);
        if ($xml->documentElement?->localName !== 'Extract') {
            throw new NovaPayException('extract_contract', 'Формат виписки NovaPay відрізняється від перевіреного контракту.');
        }
        $records = [];
        foreach (Xml::nodes($xml, 'Docs') as $node) {
            // Ці поля слід звірити з реальною API-випискою до ввімкнення operations_verified.
            $id = $node->getAttribute('UID');
            $status = Xml::value($node, 'StatusDocument');
            $date = Xml::value($node, 'PayDate');
            $credit = Xml::value($node, 'CreditCodeIBAN');
            $debit = Xml::value($node, 'DebitCodeIBAN');
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
            $records[] = [
                'provider_id' => $id, 'booked_on' => $booked->toDateString(), 'direction' => $incoming ? 'in' : 'out',
                'amount_minor' => $amount, 'status' => $mapped, 'source_status' => $status,
                'counterparty' => Xml::value($node, $incoming ? 'DebitName' : 'CreditName'),
                'purpose' => Xml::value($node, 'Purpose'),
            ];
        }
        if (count(array_unique(array_column($records, 'provider_id'))) !== count($records)) {
            throw new NovaPayException('extract_contract', 'Виписка NovaPay містить повторні ID. Період потребує перевірки.');
        }

        return $records;
    }
}
