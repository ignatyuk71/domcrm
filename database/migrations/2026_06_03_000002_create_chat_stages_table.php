<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_stages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->string('color', 20)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0)->index();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_final')->default(false);
            $table->timestamps();
        });

        DB::table('chat_stages')->insert([
            [
                'code' => 'no_stage',
                'name' => 'Без етапу',
                'color' => '#94A3B8',
                'sort_order' => 0,
                'is_default' => true,
                'is_final' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'new',
                'name' => 'Новий',
                'color' => '#3B82F6',
                'sort_order' => 10,
                'is_default' => false,
                'is_final' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'waiting_reply',
                'name' => 'Чекаємо відповідь',
                'color' => '#F59E0B',
                'sort_order' => 20,
                'is_default' => false,
                'is_final' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'order_confirmed',
                'name' => 'Замовлення підтверджене',
                'color' => '#10B981',
                'sort_order' => 30,
                'is_default' => false,
                'is_final' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'done',
                'name' => 'Виконано',
                'color' => '#6366F1',
                'sort_order' => 40,
                'is_default' => false,
                'is_final' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'closed',
                'name' => 'Закрито',
                'color' => '#0F172A',
                'sort_order' => 50,
                'is_default' => false,
                'is_final' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_stages');
    }
};
