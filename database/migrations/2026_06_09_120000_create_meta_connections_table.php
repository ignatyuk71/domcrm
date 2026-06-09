<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_connections', function (Blueprint $table) {
            $table->id();

            $table->string('page_id')->unique();          // ID сторінки Facebook
            $table->string('page_name');                  // назва сторінки
            $table->text('page_access_token');            // токен сторінки (шифрується на рівні моделі)

            $table->string('ig_account_id')->nullable();  // звʼязаний Instagram Business акаунт
            $table->string('ig_username')->nullable();

            $table->string('fb_user_id')->nullable();     // хто підключив (FB user id)
            $table->json('scopes')->nullable();           // видані дозволи

            $table->boolean('webhook_subscribed')->default(false);
            $table->string('status')->default('active');  // active | error | disconnected
            $table->text('last_error')->nullable();

            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_connections');
    }
};
