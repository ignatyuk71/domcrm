<?php

use App\Models\FiscalReceipt;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_receipts', function (Blueprint $table) {
            $table->dateTime('fiscalized_at')->nullable();
            $table->index(['status', 'fiscalized_at', 'type'], 'fiscal_receipts_analytics_index');
        });

        // Відновлюємо дату з відповіді Checkbox, не змінюючи самі чеки та updated_at.
        DB::table('fiscal_receipts')->where('status', 'success')
            ->select(['id', 'meta', 'created_at'])->chunkById(500, function ($receipts) {
                foreach ($receipts as $receipt) {
                    $meta = json_decode($receipt->meta ?? '{}', true) ?: [];
                    $date = FiscalReceipt::dateFromMeta($meta);
                    DB::table('fiscal_receipts')->where('id', $receipt->id)->update([
                        'fiscalized_at' => $date?->format('Y-m-d H:i:s') ?? $receipt->created_at,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('fiscal_receipts', function (Blueprint $table) {
            $table->dropIndex('fiscal_receipts_analytics_index');
            $table->dropColumn('fiscalized_at');
        });
    }
};
