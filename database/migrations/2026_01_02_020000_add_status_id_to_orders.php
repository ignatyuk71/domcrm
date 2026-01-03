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
            $table->foreignId('status_id')
                ->nullable()
                ->after('status')
                ->constrained('statuses');
        });

        // Бекфіл: переносимо існуючий status -> status_id за code/type=order
        $mapStatus = DB::table('statuses')
            ->where('type', 'order')
            ->pluck('id', 'code');

        DB::table('orders')->orderBy('id')->chunkById(500, function ($chunk) use ($mapStatus) {
            foreach ($chunk as $order) {
                $statusId = $mapStatus[$order->status] ?? null;
                DB::table('orders')
                    ->where('id', $order->id)
                    ->update(['status_id' => $statusId]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['status_id']);
            $table->dropColumn('status_id');
        });
    }
};
