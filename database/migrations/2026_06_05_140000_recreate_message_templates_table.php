<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Шаблони повідомлень помилково потрапили під видалення разом з чатом,
     * але вони використовуються у швидкому перегляді клієнта (CustomerQuickView).
     * Відтворюємо таблицю.
     */
    public function up(): void
    {
        if (Schema::hasTable('message_templates')) {
            return;
        }

        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->integer('sort_order')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
    }
};
