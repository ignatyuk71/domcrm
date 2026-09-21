<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('color', 7)->default('#6954df');
            $table->timestamps();
        });
        Schema::create('expense_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->timestamps();
        });
        Schema::create('expense_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 180);
            $table->text('note')->nullable();
            $table->timestamps();
        });
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->string('recipient', 255)->nullable();
            $table->foreignId('category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('expense_groups')->restrictOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('expense_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->decimal('expected_exchange_rate', 12, 6)->default(1);
            $table->date('due_on')->index();
            $table->text('note')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('idempotency_key')->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->unique(['created_by', 'idempotency_key']);
            $table->timestamps();
        });
        Schema::create('expense_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained('expenses')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('expense_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->decimal('exchange_rate', 12, 6);
            $table->unsignedBigInteger('amount_uah_minor');
            $table->date('paid_on')->index();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key')->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->unique(['expense_id', 'idempotency_key']);
            $table->timestamps();
        });
        Schema::create('expense_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('expense_payments')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 80);
            $table->unsignedInteger('size');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        $now = now();
        foreach (['Матеріали' => '#6954df', 'Доставка' => '#3498db', 'Реклама' => '#ec8a3f', 'Комунальні послуги' => '#38a690', 'Оренда' => '#b76bcc', 'Послуги' => '#6885a8', 'Інше' => '#87909e'] as $name => $color) {
            DB::table('expense_categories')->insert(compact('name', 'color') + ['created_at' => $now, 'updated_at' => $now]);
        }
        foreach (['Банківський рахунок', 'Картка', 'Каса'] as $name) {
            DB::table('expense_accounts')->insert(compact('name') + ['created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        foreach (['expense_receipts', 'expense_payments', 'expenses', 'expense_groups', 'expense_accounts', 'expense_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
