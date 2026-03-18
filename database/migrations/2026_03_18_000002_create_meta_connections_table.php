<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Створюємо нову таблицю під нормальне підключення Meta.
     * Стару facebook_settings не чіпаємо, щоб міграція була безпечною.
     */
    public function up(): void
    {
        Schema::create('meta_connections', function (Blueprint $table) {
            $table->id();

            // Людська назва підключення для адмінки.
            $table->string('name')->nullable();

            // Тип інтеграції. Зараз працюємо з одним стеком Meta, але лишаємо поле
            // для прозорості та подальшої підтримки кількох підключень.
            $table->string('provider', 32)->default('meta')->index();

            // Дані застосунку / користувача / сторінки Facebook.
            $table->string('app_id')->nullable();
            $table->string('meta_user_id')->nullable()->index();
            $table->string('meta_user_name')->nullable();
            $table->string('facebook_page_id')->nullable()->index();
            $table->string('facebook_page_name')->nullable();

            // Дані Instagram Business акаунта, прив'язаного до сторінки.
            $table->string('instagram_account_id')->nullable()->index();
            $table->string('instagram_username')->nullable();
            $table->string('business_account_id')->nullable()->index();

            // Токени та параметри авторизації.
            // user_access_token - токен користувача Meta після OAuth.
            // access_token - фінальний page access token, яким працюватиме чат.
            $table->longText('user_access_token')->nullable();
            $table->timestamp('user_token_expires_at')->nullable();
            $table->longText('access_token')->nullable();
            $table->string('token_type', 32)->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('granted_scopes')->nullable();

            // Параметри вебхука.
            $table->string('verify_token')->nullable();
            $table->string('webhook_secret')->nullable();
            $table->boolean('webhook_subscribed')->default(false);
            $table->json('webhook_fields')->nullable();

            // Стан підключення та технічна діагностика.
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_token_refresh_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('profile_payload')->nullable();

            // Хто підключав інтеграцію.
            $table->foreignId('connected_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // Один page_id / instagram_account_id має відповідати одному підключенню.
            $table->unique('facebook_page_id');
            $table->unique('instagram_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_connections');
    }
};
