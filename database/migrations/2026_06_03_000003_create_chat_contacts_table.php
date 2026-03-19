<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_connection_id')
                ->constrained('meta_connections')
                ->restrictOnDelete();
            $table->foreignId('customer_id')
                ->nullable()
                ->constrained('customers')
                ->nullOnDelete();
            $table->enum('platform', ['messenger', 'instagram'])->index();
            $table->string('external_user_id', 191);
            $table->string('external_username', 191)->nullable();
            $table->string('display_name', 191)->nullable();
            $table->string('first_name', 120)->nullable();
            $table->string('last_name', 120)->nullable();
            $table->string('avatar_path')->nullable();
            $table->text('avatar_original_url')->nullable();
            $table->json('profile_payload')->nullable();
            $table->timestamp('last_profile_sync_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['meta_connection_id', 'platform', 'external_user_id'],
                'chat_contacts_external_unique'
            );
            $table->index(['customer_id', 'platform'], 'chat_contacts_customer_platform_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_contacts');
    }
};
