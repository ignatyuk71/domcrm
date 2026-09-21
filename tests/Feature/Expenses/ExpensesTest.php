<?php

namespace Tests\Feature\Expenses;

use App\Models\ExpenseAccount;
use App\Models\ExpenseCategory;
use App\Models\ExpenseGroup;
use App\Models\ExpenseReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpensesTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $user = User::factory()->create(['role' => 'owner']);
        $this->actingAs($user);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['title' => 'Підошва з Китаю', 'recipient' => 'Постачальник', 'category_id' => ExpenseCategory::first()->id,
            'account_id' => ExpenseAccount::first()->id, 'amount' => '100.00', 'currency' => 'USD', 'expected_exchange_rate' => '40.123456',
            'due_on' => now('Europe/Kyiv')->toDateString(), 'note' => 'Тестова витрата'], $overrides);
    }

    private function payment(array $overrides = []): array
    {
        return array_replace(['amount' => '40.00', 'account_id' => ExpenseAccount::first()->id, 'paid_on' => now('Europe/Kyiv')->toDateString(), 'exchange_rate' => '41.123456'], $overrides);
    }

    private function create(array $overrides = []): array
    {
        return $this->postJson('/api/expenses', $this->payload($overrides))->assertCreated()->json('data');
    }

    public function test_owner_authorization_covers_reads_writes_export_and_receipts(): void
    {
        $this->getJson('/api/expenses')->assertUnauthorized();
        $owner = $this->owner();
        $e = $this->create(['payment' => $this->payment()]);
        $receipt = ExpenseReceipt::create(['payment_id' => $e['payments'][0]['id'], 'path' => 'private.pdf', 'original_name' => 'receipt.pdf', 'mime_type' => 'application/pdf', 'size' => 1, 'created_by' => $owner->id]);
        foreach (['operator', 'packer'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            foreach (['/expenses', '/api/expenses', '/api/expenses/meta', '/api/expenses/export', '/api/expenses/'.$e['id'], '/api/expenses/receipts/'.$receipt->id] as $url) {
                $this->getJson($url)->assertForbidden();
            }
            $this->postJson('/api/expenses', $this->payload())->assertForbidden();
            $this->postJson('/api/expenses/categories', ['name' => 'Заборонено'])->assertForbidden();
        }
        $owner->update(['is_active' => false]);
        $this->actingAs($owner)->getJson('/api/expenses')->assertForbidden();
    }

    public function test_payments_are_exact_idempotent_and_optimistically_locked(): void
    {
        $this->owner();
        $key = (string) Str::uuid();
        $data = $this->payload(['idempotency_key' => $key, 'payment' => $this->payment()]);
        $e = $this->postJson('/api/expenses', $data)->assertCreated()->json('data');
        $this->assertSame('1644.94', $e['payments'][0]['amount_uah']);
        $this->postJson('/api/expenses', $data)->assertCreated()->assertJsonPath('data.id', $e['id']);
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseCount('expense_payments', 1);
        $data['title'] = 'Інша оплата';
        $this->postJson('/api/expenses', $data)->assertConflict();
        $url = '/api/expenses/'.$e['id'].'/payments';
        $payment = $this->payment(['amount' => '20.00', 'version' => 1, 'idempotency_key' => (string) Str::uuid()]);
        $this->postJson($url, $payment)->assertCreated()->assertJsonPath('data.paid_amount', '60.00')->assertJsonPath('data.version', 2);
        $this->postJson($url, $payment)->assertCreated()->assertJsonPath('data.paid_amount', '60.00');
        $this->postJson($url, $this->payment(['version' => 1]))->assertConflict();
        $this->postJson($url, $this->payment(['version' => 2, 'amount' => '40.01']))->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson($url, $this->payment(['version' => 2, 'paid_on' => now('Europe/Kyiv')->addDay()->toDateString()]))->assertUnprocessable();
        $this->assertDatabaseCount('expense_payments', 2);
    }

    public function test_update_delete_parent_binding_and_amount_currency_guards(): void
    {
        $this->owner();
        $e = $this->create(['payment' => $this->payment()]);
        $second = $this->create();
        $url = '/api/expenses/'.$e['id'];
        $paymentId = $e['payments'][0]['id'];
        $this->putJson($url, $this->payload(['version' => 1, 'amount' => '30']))->assertUnprocessable();
        $this->putJson($url, $this->payload(['version' => 1, 'currency' => 'EUR']))->assertUnprocessable();
        $this->putJson('/api/expenses/'.$second['id'].'/payments/'.$paymentId, $this->payment(['version' => 1]))->assertNotFound();
        $this->putJson($url.'/payments/'.$paymentId, $this->payment(['version' => 1, 'amount' => '35']))->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.remaining_amount', '65.00');
        $this->deleteJson($url.'/payments/'.$paymentId, ['version' => 2])->assertOk()->assertJsonPath('data.version', 3)->assertJsonPath('data.paid_amount', '0.00');
        $this->deleteJson($url, ['version' => 1])->assertConflict();
        $this->deleteJson($url, ['version' => 3])->assertOk();
        $this->getJson($url)->assertNotFound();
    }

    public function test_summary_filters_plans_groups_and_literal_search(): void
    {
        $this->owner();
        $group = ExpenseGroup::create(['name' => 'Партія А']);
        $e = $this->create(['title' => 'Ставка 10%_!', 'group_id' => $group->id, 'payment' => $this->payment()]);
        $this->getJson('/api/expenses?account_id='.$e['account_id'])->assertOk()->assertJsonPath('summary.paid_amount', '1644.94')->assertJsonPath('summary.pending_amount', '2407.41')->assertJsonPath('breakdown.categories.0.amount', '1644.94')->assertJsonPath('breakdown.groups.0.amount', '1644.94');
        $this->getJson('/api/expenses?view=planned')->assertOk()->assertJsonPath('data.0.remaining_amount', '60.00')->assertJsonPath('data.0.status', 'partial');
        $this->getJson('/api/expenses?view=groups')->assertOk()->assertJsonPath('data.0.paid_amount', '1644.94')->assertJsonPath('data.0.pending_amount', '2407.41');
        $this->getJson('/api/expenses?q='.urlencode('10%_!'))->assertOk()->assertJsonPath('meta.total', 1);
        $newGroup = ExpenseGroup::create(['name' => 'Без оплат']);
        $this->create(['group_id' => $newGroup->id]);
        $this->getJson('/api/expenses?view=groups')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/expenses?account_id=999999')->assertUnprocessable();
    }

    public function test_private_receipts_validate_serve_delete_and_filter_without_changing_summary(): void
    {
        Storage::fake('local');
        $this->owner();
        $e = $this->create(['payment' => $this->payment()]);
        $other = $this->create(['payment' => $this->payment()]);
        $url = '/api/expenses/'.$e['id'].'/payments/'.$e['payments'][0]['id'].'/receipts';
        $this->postJson($url, ['files' => [UploadedFile::fake()->createWithContent('evil.jpg', '<svg><script>alert(1)</script></svg>')]])->assertUnprocessable();
        $pdf = UploadedFile::fake()->createWithContent('квитанція.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
        $data = $this->postJson($url, ['files' => [$pdf]])->assertCreated()->json('data');
        $this->postJson($url, ['files' => [UploadedFile::fake()->createWithContent('повтор.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF")]])->assertCreated()->assertJsonCount(1, 'data.payments.0.receipts')->assertJsonPath('data.version', $data['version']);
        $receipt = $data['payments'][0]['receipts'][0];
        $model = ExpenseReceipt::findOrFail($receipt['id']);
        Storage::disk('local')->assertExists($model->path);
        $this->get($receipt['url'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
        $this->get($receipt['download_url'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->getJson('/api/expenses?missing_receipts=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('summary.paid_count', 2)->assertJsonPath('summary.missing_receipts_count', 1);
        $this->deleteJson($receipt['url'])->assertOk();
        $this->getJson($receipt['url'])->assertNotFound();
        $this->assertDatabaseCount('expense_receipts', 0);
    }

    public function test_csv_covers_all_pages_and_neutralizes_formulas(): void
    {
        $this->owner();
        $e = $this->create(['title' => '=SUM(A1)', 'payment' => $this->payment()]);
        for ($i = 0; $i < 26; $i++) {
            $this->create(['title' => 'Платіж '.$i, 'payment' => $this->payment()]);
        }
        $this->getJson('/api/expenses')->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.total', 27);
        $response = $this->get('/api/expenses/export')->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("'=SUM(A1)", $csv);
        $this->assertStringContainsString('1644.94', $csv);
        $this->assertStringContainsString('Платіж 25', $csv);
        $this->assertSame(28, substr_count($csv, "\n"));
    }

    public function test_uah_rate_is_one_and_invalid_amount_never_writes(): void
    {
        $this->owner();
        $e = $this->create(['currency' => 'UAH', 'payment' => $this->payment()]);
        $this->assertSame('1.000000', $e['expected_exchange_rate']);
        $this->assertSame('40.00', $e['payments'][0]['amount_uah']);
        foreach (['1e2', '-5', '1.001', '10000000.00'] as $amount) {
            $this->postJson('/api/expenses', $this->payload(['amount' => $amount]))->assertUnprocessable();
        }
        $this->assertDatabaseCount('expenses', 1);
    }
}
