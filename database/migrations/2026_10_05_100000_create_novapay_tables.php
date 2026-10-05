<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('novapay_connections', function (Blueprint $table) {
            $table->id();
            $table->string('login')->unique();
            $table->text('refresh_token');
            $table->text('public_certificate');
            $table->text('access_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->boolean('requires_auth')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('novapay_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('novapay_connections')->restrictOnDelete();
            $table->unsignedInteger('provider_id');
            $table->unsignedInteger('client_id');
            $table->string('client_name');
            $table->string('iban', 34);
            $table->char('currency', 3);
            $table->string('timezone', 64)->default('Europe/Kyiv');
            $table->boolean('enabled')->default(false)->index();
            $table->date('import_from')->nullable();
            $table->timestamps();
            $table->unique(['connection_id', 'provider_id']);
        });
        Schema::create('novapay_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('novapay_accounts')->restrictOnDelete();
            $table->string('type', 24);
            $table->bigInteger('amount_minor');
            $table->timestamp('received_at');
            $table->timestamps();
            $table->unique(['account_id', 'type']);
        });
        Schema::create('novapay_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('novapay_accounts')->restrictOnDelete();
            $table->string('provider_id', 128);
            $table->date('booked_on');
            $table->string('direction', 8);
            $table->bigInteger('amount_minor');
            $table->string('status', 24);
            $table->string('source_status', 64);
            $table->string('counterparty')->nullable();
            $table->text('purpose')->nullable();
            $table->char('fingerprint', 64);
            $table->timestamps();
            $table->unique(['account_id', 'provider_id']);
            $table->index(['account_id', 'booked_on', 'direction', 'status'], 'novapay_operations_period_index');
        });
        Schema::create('novapay_operation_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operation_id')->constrained('novapay_operations')->restrictOnDelete();
            $table->json('snapshot');
            $table->timestamp('created_at');
        });
        Schema::create('novapay_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('novapay_connections')->restrictOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('novapay_accounts')->restrictOnDelete();
            $table->string('source', 24);
            $table->string('status', 24)->default('queued');
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->unsignedInteger('records_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'source', 'status']);
            $table->index(['connection_id', 'source', 'id']);
        });
    }

    public function down(): void
    {
        foreach (['novapay_sync_runs', 'novapay_operation_revisions', 'novapay_operations', 'novapay_balances', 'novapay_accounts', 'novapay_connections'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
