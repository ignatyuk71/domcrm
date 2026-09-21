<?php

namespace Tests\Feature\Expenses;

use App\Models\ExpensePayment;
use App\Models\ExpenseReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExpenseDemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_demo_has_one_private_receipt_and_is_not_duplicated(): void
    {
        $this->app['env'] = 'local';
        Storage::fake('local');
        User::factory()->create(['role' => User::ROLE_OWNER, 'is_active' => true]);

        $this->artisan('expenses:demo')->assertSuccessful();
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseCount('expense_payments', 1);
        $this->assertDatabaseCount('expense_receipts', 1);
        $this->assertSame(7200000, (int) ExpensePayment::sum('amount_uah_minor'));
        foreach (ExpenseReceipt::all() as $receipt) {
            Storage::disk('local')->assertExists($receipt->path);
            $this->assertStringStartsWith('expenses/receipts/demo/', $receipt->path);
            $this->assertContains($receipt->mime_type, ['application/pdf', 'image/png']);
        }

        $this->artisan('expenses:demo')->assertSuccessful();
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseCount('expense_receipts', 1);
    }

    public function test_demo_cannot_run_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->artisan('expenses:demo')->assertFailed();
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_production_demo_requires_explicit_force(): void
    {
        $this->app['env'] = 'production';
        Storage::fake('local');
        User::factory()->create(['role' => User::ROLE_OWNER, 'is_active' => true]);

        $this->artisan('expenses:demo', ['--force' => true])->assertSuccessful();
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseHas('expenses', ['title' => 'Міжнародна доставка', 'is_demo' => true]);
        $this->assertDatabaseCount('expense_receipts', 1);
    }

    public function test_remote_database_url_cannot_bypass_local_host_guard(): void
    {
        $this->app['env'] = 'local';
        $original = DB::getDefaultConnection();
        config(['database.connections.expense_demo_remote' => [
            'driver' => 'mysql', 'host' => '127.0.0.1',
            'url' => 'mysql://demo:demo@remote.invalid/expenses',
            'database' => 'expenses', 'username' => 'demo', 'password' => 'demo',
        ]]);
        DB::setDefaultConnection('expense_demo_remote');
        try {
            $this->artisan('expenses:demo')->assertFailed();
        } finally {
            DB::setDefaultConnection($original);
        }
        $this->assertDatabaseCount('expenses', 0);
    }
}
