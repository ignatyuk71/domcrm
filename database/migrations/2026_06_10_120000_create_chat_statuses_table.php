<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable()->unique(); // new, in_progress, order, closed — для автоправил
            $table->string('name');
            $table->string('icon')->nullable();           // bi-stars, bi-bag-check...
            $table->string('color', 20)->nullable();      // hex для бейджа
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::table('inbox_conversations', function (Blueprint $table) {
            $table->foreignId('chat_status_id')->nullable()->after('status')
                ->constrained('chat_statuses')->nullOnDelete();
        });

        // Базовий набір
        $now = now();
        DB::table('chat_statuses')->insert([
            ['code' => 'new', 'name' => 'Новий', 'icon' => 'bi-stars', 'color' => '#0084ff', 'sort_order' => 1, 'is_default' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'in_progress', 'name' => 'В роботі', 'icon' => 'bi-chat-dots', 'color' => '#f59f00', 'sort_order' => 2, 'is_default' => false, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'order', 'name' => 'Замовлення', 'icon' => 'bi-bag-check', 'color' => '#2fb344', 'sort_order' => 3, 'is_default' => false, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'closed', 'name' => 'Закрито', 'icon' => 'bi-check2-circle', 'color' => '#868e96', 'sort_order' => 4, 'is_default' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Наявні розмови — у статус за замовчуванням
        $defaultId = DB::table('chat_statuses')->where('is_default', true)->value('id');
        if ($defaultId) {
            DB::table('inbox_conversations')->whereNull('chat_status_id')->update(['chat_status_id' => $defaultId]);
        }
    }

    public function down(): void
    {
        Schema::table('inbox_conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chat_status_id');
        });
        Schema::dropIfExists('chat_statuses');
    }
};
