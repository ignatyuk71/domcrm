<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\ExpenseAccount;
use App\Models\ExpenseCategory;
use App\Models\ExpenseGroup;
use App\Models\ExpensePayment;
use App\Models\ExpenseReceipt;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SeedExpenseDemo extends Command
{
    protected $signature = 'expenses:demo {--force : Явно дозволити один демонстраційний запис поза локальним середовищем}';

    protected $description = 'Додати одну демонстраційну міжнародну доставку з квитанцією';

    public function handle(): int
    {
        // Беремо остаточну конфігурацію: DB_URL може перевизначити DB_HOST.
        $connection = DB::connection();
        $driver = $connection->getConfig('driver');
        $host = $connection->getConfig('host');
        if (! $this->option('force') && (! app()->environment('local') || ($driver !== 'sqlite' && ! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)))) {
            $this->error('Поза локальним застосунком і локальною базою потрібен явний параметр --force.');

            return self::FAILURE;
        }

        if (Expense::where('is_demo', true)->exists()) {
            $this->info('Демонстраційні оплати вже існують. Повторно їх не додано.');

            return self::SUCCESS;
        }

        $owner = User::where('role', User::ROLE_OWNER)->where('is_active', true)->first();
        if (! $owner) {
            $this->error('Спочатку потрібен активний власник CRM.');

            return self::FAILURE;
        }

        $copied = [];
        try {
            DB::transaction(function () use ($owner, &$copied) {
                $categories = [];
                foreach (['Доставка' => '#3498db'] as $name => $color) {
                    $categories[$name] = ExpenseCategory::firstOrCreate(['name' => $name], ['color' => $color])->id;
                }
                $accounts = [];
                foreach (['Рахунок ФОП'] as $name) {
                    $accounts[$name] = ExpenseAccount::firstOrCreate(['name' => $name])->id;
                }
                $groups = [];
                foreach (['Підошва · партія №24'] as $name) {
                    $groups[$name] = ExpenseGroup::create(['name' => 'ДЕМО · '.$name, 'note' => 'Демонстраційна група для огляду.'])->id;
                }

                // Умовна сума для огляду, не реальна оплата.
                $rows = [
                    ['Міжнародна доставка', 'Доставка', 'Підошва · партія №24', 'Рахунок ФОП', 7200000, 'UAH', '1.000000', 7200000, 0, 'demo-delivery.pdf'],
                ];
                $today = now(config('app.timezone', 'Europe/Kyiv'))->startOfDay();
                foreach ($rows as [$title, $category, $group, $account, $amount, $currency, $rate, $paid, $offset, $fixture]) {
                    $paidOn = $today->copy()->subDays(min($offset, $today->day - 1))->toDateString();
                    $expense = Expense::create([
                        'title' => $title, 'recipient' => 'Демонстраційний постачальник',
                        'category_id' => $categories[$category], 'group_id' => $group ? $groups[$group] : null,
                        'account_id' => $accounts[$account], 'amount_minor' => $amount, 'currency' => $currency,
                        'expected_exchange_rate' => $rate,
                        'due_on' => $paid < $amount ? $today->copy()->endOfMonth()->toDateString() : $paidOn,
                        'note' => 'Демонстраційний запис для огляду. Реальний платіж не здійснювався.',
                        'is_demo' => true, 'created_by' => $owner->id,
                    ]);
                    if ($paid === 0) {
                        continue;
                    }
                    $payment = ExpensePayment::create([
                        'expense_id' => $expense->id, 'account_id' => $accounts[$account],
                        'amount_minor' => $paid, 'exchange_rate' => $rate,
                        'amount_uah_minor' => $currency === 'USD' ? $paid * 40 : $paid,
                        'paid_on' => $paidOn, 'note' => 'Демонстраційна оплата.', 'created_by' => $owner->id,
                    ]);
                    if (! $fixture) {
                        continue;
                    }
                    $contents = file_get_contents(database_path('seeders/fixtures/expenses/'.$fixture));
                    $path = 'expenses/receipts/demo/'.Str::uuid().'.'.pathinfo($fixture, PATHINFO_EXTENSION);
                    if ($contents === false || ! Storage::disk('local')->put($path, $contents)) {
                        throw new RuntimeException('Не вдалося зберегти демонстраційну квитанцію.');
                    }
                    $copied[] = $path;
                    ExpenseReceipt::create([
                        'payment_id' => $payment->id, 'path' => $path, 'original_name' => $fixture,
                        'mime_type' => str_ends_with($fixture, '.pdf') ? 'application/pdf' : 'image/png',
                        'content_hash' => hash('sha256', $contents),
                        'size' => strlen($contents), 'created_by' => $owner->id,
                    ]);
                }
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($copied);
            throw $exception;
        }

        $this->info('Додано одну демонстраційну міжнародну доставку, одну оплату й одну квитанцію. Сторінка: /expenses');

        return self::SUCCESS;
    }
}
