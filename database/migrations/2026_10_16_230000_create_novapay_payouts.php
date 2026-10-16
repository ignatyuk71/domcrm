<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('novapay_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('novapay_accounts')->restrictOnDelete();
            $table->string('provider_id', 128);
            $table->string('register_number', 128);
            $table->string('ttn', 14)->index();
            $table->date('paid_on');
            $table->bigInteger('gross_minor');
            $table->bigInteger('fee_minor');
            $table->bigInteger('net_minor');
            $table->boolean('active')->default(true);
            $table->timestamp('reported_at');
            $table->foreignId('operation_id')->nullable()->constrained('novapay_operations')->nullOnDelete();
            $table->char('fingerprint', 64);
            $table->timestamps();
            $table->unique(['account_id', 'provider_id']);
            $table->index(['account_id', 'paid_on', 'active']);
        });
        Schema::create('novapay_payout_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payout_id')->constrained('novapay_payouts')->restrictOnDelete();
            $table->json('snapshot');
            $table->timestamp('created_at');
        });
        Schema::table('novapay_sync_runs', fn (Blueprint $table) => $table->unsignedInteger('statement_id')->nullable());
    }

    public function down(): void
    {
        Schema::table('novapay_sync_runs', fn (Blueprint $table) => $table->dropColumn('statement_id'));
        Schema::dropIfExists('novapay_payout_revisions');
        Schema::dropIfExists('novapay_payouts');
    }
};
