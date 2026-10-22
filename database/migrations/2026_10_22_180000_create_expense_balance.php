<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_accounts', function (Blueprint $table) {
            $table->string('system_key', 40)->nullable()->unique();
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->unsignedBigInteger('category_id')->nullable()->change();
        });
        Schema::create('expense_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained('expense_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor')->default(0);
            $table->unsignedBigInteger('incoming_minor')->default(0);
            $table->timestamps();
        });
        Schema::create('expense_balance_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('balance_id')->constrained('expense_balances')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('expense_payments')->nullOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->foreignId('source_account_id')->nullable()->constrained('expense_accounts')->restrictOnDelete();
            $table->string('kind', 30);
            $table->bigInteger('amount_minor');
            $table->unsignedBigInteger('balance_after_minor');
            $table->string('title');
            $table->text('note')->nullable();
            $table->date('occurred_on');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key')->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['created_by', 'idempotency_key']);
            $table->index(['balance_id', 'id']);
        });
        // Нове джерело не змінює старі рахунки, оплати чи їхні суми.
        $name = 'Баланс';
        for ($suffix = 1; DB::table('expense_accounts')->where('name', $name)->exists(); $suffix++) {
            $name = 'Баланс CRM'.($suffix > 1 ? ' '.$suffix : '');
        }
        $now = now();
        $accountId = DB::table('expense_accounts')->insertGetId(['name' => $name, 'system_key' => 'balance', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('expense_balances')->insert(['id' => 1, 'account_id' => $accountId, 'amount_minor' => 0, 'created_at' => $now, 'updated_at' => $now]);
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_balance_entries');
        Schema::dropIfExists('expense_balances');
        // Використане джерело зберігаємо для історії; nullable-категорії також зберігаємо.
        DB::table('expense_accounts')->where('system_key', 'balance')
            ->whereNotIn('id', DB::table('expense_payments')->select('account_id'))
            ->whereNotIn('id', DB::table('expenses')->whereNotNull('account_id')->select('account_id'))->delete();
        Schema::table('expense_accounts', function (Blueprint $table) {
            $table->dropColumn('system_key');
        });
    }
};
