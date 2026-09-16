<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_cost_batches', function (Blueprint $table) {
            $table->id();
            $table->string('component', 32);
            $table->string('name', 160);
            $table->date('purchased_on')->nullable();
            $table->unsignedInteger('quantity');
            $table->json('inputs');
            $table->decimal('total_uah', 14, 2);
            $table->decimal('unit_cost_uah', 16, 6);
            $table->text('note')->nullable();
            $table->uuid('request_key')->unique();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['component', 'id']);
        });
        Schema::create('production_cost_batch_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('production_cost_batches')->restrictOnDelete();
            $table->json('before')->nullable();
            $table->json('after');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_cost_batch_revisions');
        Schema::dropIfExists('production_cost_batches');
    }
};
