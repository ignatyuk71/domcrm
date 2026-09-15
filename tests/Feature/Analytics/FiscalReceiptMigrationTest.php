<?php

namespace Tests\Feature\Analytics;

use App\Models\FiscalReceipt;
use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\TestCase;

class FiscalReceiptMigrationTest extends TestCase
{
    // DDL у MySQL завершує транзакцію: перевіряємо міграцію на окремій чистій схемі.
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;
        $this->beforeApplicationDestroyed(function () {
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_backfill_preserves_update_time_and_uses_receipt_timezone(): void
    {
        $order = Order::create(['order_number' => 'migration-test', 'status' => 'new', 'currency' => 'UAH']);
        $receipt = FiscalReceipt::create([
            'order_id' => $order->id, 'payload_hash' => 'migration-test',
            'fiscal_code' => 'migration-test', 'type' => 'sell', 'status' => 'success', 'total_amount' => 10000,
            'meta' => ['fiscal_date' => '2026-07-31T21:30:00+00:00'],
        ]);
        $updated = $receipt->getRawOriginal('updated_at');
        $migration = require database_path('migrations/2026_09_15_120000_add_fiscalized_at_to_fiscal_receipts.php');
        $migration->down();
        $migration->up();

        $this->assertSame('2026-08-01 00:30:00', $receipt->fresh()->fiscalized_at->format('Y-m-d H:i:s'));
        $this->assertSame($updated, $receipt->fresh()->getRawOriginal('updated_at'));
    }
}
