<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Зносимо старе ядро чату повністю.
     * Не чіпаємо meta_connections, customers, orders та інші бізнес-таблиці.
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('conversation_tag_links');
        Schema::dropIfExists('conversation_tags');
        Schema::dropIfExists('conversation_meta');
        Schema::dropIfExists('facebook_messages');
        Schema::dropIfExists('conversations');

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Відкат для свідомого reset-видалення не підтримуємо.
     * Нове ядро буде підніматися окремими міграціями.
     */
    public function down(): void
    {
        //
    }
};
