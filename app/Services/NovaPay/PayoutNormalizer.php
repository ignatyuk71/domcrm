<?php

namespace App\Services\NovaPay;

use App\Models\NovaPayAccount;
use Carbon\CarbonImmutable;

class PayoutNormalizer
{
    public function normalize(string $csv, NovaPayAccount $account, string $from, string $to): array
    {
        if (strlen($csv) > 10 * 1024 * 1024 || ! mb_check_encoding($csv, 'UTF-8')) {
            throw new NovaPayException('register_contract', 'Непідтримуваний розмір або кодування реєстру післяплати.');
        }
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $csv));
        rewind($stream);
        try {
            $header = fgetcsv($stream, 0, ',', '"', '');
            $required = ['Дата перерахунку коштів', 'Номер реєстру', 'Сума принятих коштів', 'Сума утриманої винагороди', 'Сума перерахованих коштів', '№ ЕН НП', 'Номер рахунку отримувача', 'Унікальний обліковий номер фінансової операції'];
            if (! is_array($header) || count(array_unique($header)) !== count($header) || array_diff($required, $header)) {
                throw new NovaPayException('register_contract', 'Колонки реєстру NovaPay змінилися. Потрібна перевірка формату.');
            }
            $records = [];
            while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                if ($row === [null]) continue;
                if (count($row) !== count($header)) {
                    throw new NovaPayException('register_contract', 'Реєстр містить неповний рядок. Імпорт не виконано.');
                }
                $data = array_combine($header, array_map('trim', $row));
                $iban = strtoupper(str_replace(' ', '', $data['Номер рахунку отримувача']));
                // Реєстр підприємства може містити інші його рахунки; їх не зараховуємо цьому рахунку.
                if (! preg_match('/^UA\d{27}$/D', $iban)) throw new NovaPayException('register_contract', 'Некоректний рахунок у реєстрі післяплати.');
                if ($iban !== $account->iban) continue;
                $date = $data['Дата перерахунку коштів'];
                try {
                    $paid = CarbonImmutable::createFromFormat('!d.m.Y H:i', $date, $account->timezone);
                    if (! $paid || $paid->format('d.m.Y H:i') !== $date || $paid->toDateString() < $from || $paid->toDateString() > $to) throw new \RuntimeException;
                } catch (\Throwable) {
                    throw new NovaPayException('register_contract', 'Некоректна дата виплати в реєстрі післяплати.');
                }
                $id = $data['Унікальний обліковий номер фінансової операції'];
                $register = $data['Номер реєстру'];
                $ttn = $data['№ ЕН НП'];
                $gross = Money::minor(str_replace(',', '.', $data['Сума принятих коштів']));
                $fee = Money::minor(str_replace(',', '.', $data['Сума утриманої винагороди']));
                $net = Money::minor(str_replace(',', '.', $data['Сума перерахованих коштів']));
                if ($id === '' || strlen($id) > 128 || $register === '' || strlen($register) > 128 || ! preg_match('/^\d{14}$/D', $ttn)
                    || $gross <= 0 || $fee < 0 || $net <= 0 || $gross - $fee !== $net || isset($records[$id])) {
                    throw new NovaPayException('register_contract', 'Реєстр має неоднозначний ID, ТТН або суми. Імпорт не виконано.');
                }
                $records[$id] = ['provider_id' => $id, 'register_number' => $register, 'ttn' => $ttn, 'paid_on' => $paid->toDateString(), 'gross_minor' => $gross, 'fee_minor' => $fee, 'net_minor' => $net];
            }

            return array_values($records);
        } finally {
            fclose($stream);
        }
    }
}
