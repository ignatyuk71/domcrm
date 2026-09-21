<?php

namespace Tests\Feature\Expenses;

use App\Models\Expense;
use App\Models\ExpenseAccount;
use App\Models\ExpenseCategory;
use App\Models\ExpenseReceipt;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExpenseReceiptStorageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Окрема in-memory БД дає справжній commit і не торкається MySQL або стану RefreshDatabase інших тестів.
        config(['database.connections.expense_receipt_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'url' => null,
            'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('expense_receipt_test');
        $this->artisan('migrate:fresh', ['--database' => 'expense_receipt_test', '--force' => true])->assertSuccessful();
    }

    private function expense(): array
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner);
        $e = Expense::create(['title' => 'Квитанція', 'category_id' => ExpenseCategory::first()->id, 'amount_minor' => 10000, 'currency' => 'UAH', 'expected_exchange_rate' => '1', 'due_on' => now()->toDateString(), 'created_by' => $owner->id, 'version' => 1]);
        $payment = $e->payments()->create(['account_id' => ExpenseAccount::first()->id, 'amount_minor' => 10000, 'exchange_rate' => '1', 'amount_uah_minor' => 10000, 'paid_on' => now()->toDateString(), 'created_by' => $owner->id]);

        return [$e, $payment];
    }

    public function test_expense_delete_removes_files_only_after_commit(): void
    {
        Storage::fake('local');
        [$e, $p] = $this->expense();
        Storage::disk('local')->put('expenses/receipts/a.pdf', 'PDF');
        $receipt = $p->receipts()->create(['path' => 'expenses/receipts/a.pdf', 'original_name' => 'a.pdf', 'mime_type' => 'application/pdf', 'size' => 3, 'created_by' => $e->created_by]);
        DB::beginTransaction();
        $this->deleteJson('/api/expenses/'.$e->id, ['version' => 1])->assertOk();
        Storage::disk('local')->assertExists($receipt->path);
        DB::rollBack();
        $this->assertDatabaseHas('expense_receipts', ['id' => $receipt->id]);
        Storage::disk('local')->assertExists($receipt->path);
        $this->deleteJson('/api/expenses/'.$e->id, ['version' => 1])->assertOk();
        Storage::disk('local')->assertMissing($receipt->path);
        $this->assertDatabaseCount('expense_receipts', 0);
    }

    public function test_upload_failure_rolls_back_rows_and_removes_partial_files(): void
    {
        $disk = Storage::fake('local');
        [$e, $p] = $this->expense();
        // Відмова другого запису моделюється подією моделі після першого збереженого файлу.
        $count = 0;
        ExpenseReceipt::creating(function () use (&$count) {
            if (++$count === 2) {
                throw new \RuntimeException('Тестова відмова запису.');
            }
        });
        $pdf = fn ($name, $text) => UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n$text\n%%EOF");
        $this->postJson('/api/expenses/'.$e->id.'/payments/'.$p->id.'/receipts', ['files' => [$pdf('a.pdf', 'AAA'), $pdf('b.pdf', 'BBB')]])->assertServerError();
        $this->assertDatabaseCount('expense_receipts', 0);
        $this->assertSame([], $disk->allFiles());
        ExpenseReceipt::flushEventListeners();
    }
}
