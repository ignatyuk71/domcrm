<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dateTime('status_changed_at')->nullable();
        });

        // Старий час відновлюємо лише з останньої події, яка відповідає поточному статусу.
        $latestIds = DB::table('order_status_changes')->selectRaw('MAX(id)')->groupBy('order_id');
        DB::table('order_status_changes')->whereIn('id', $latestIds)
            ->select(['id', 'order_id', 'new_status', 'new_status_id', 'occurred_at'])
            ->chunkById(500, function ($changes) {
                foreach ($changes as $change) {
                    DB::table('orders')->where('id', $change->order_id)
                        ->whereNull('status_changed_at')
                        ->where('status', $change->new_status)
                        ->where('status_id', $change->new_status_id)
                        // Не перезаписуємо результат зміни, що сталася під час міграції.
                        ->whereNotExists(function ($query) use ($change) {
                            $query->selectRaw('1')->from('order_status_changes')
                                ->where('order_id', $change->order_id)->where('id', '>', $change->id);
                        })
                        ->update(['status_changed_at' => $change->occurred_at]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('status_changed_at');
        });
    }
};
