<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gpt_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->text('api_key')->nullable();
            $table->string('model', 100)->nullable();
            $table->text('store_policies')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
        });

        Schema::create('gpt_dialogues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('title', 100);
            $table->json('memory')->nullable();
            $table->timestamps();
            $table->index(['created_by', 'updated_at']);
        });

        Schema::create('gpt_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gpt_dialogue_id')->constrained('gpt_dialogues')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->uuid('request_id');
            $table->string('status', 20)->default('pending');
            $table->text('input_message');
            $table->unsignedBigInteger('gallery_group_id')->nullable();
            $table->string('model', 100);
            $table->unsignedInteger('settings_version');
            $table->string('prompt_version', 40)->default('gpt-drafts-v1');
            $table->json('result')->nullable();
            $table->json('evidence')->nullable();
            $table->json('usage')->nullable();
            $table->string('error_code', 50)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['created_by', 'request_id']);
            $table->index(['gpt_dialogue_id', 'status', 'id']);
            $table->index(['created_by', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gpt_runs');
        Schema::dropIfExists('gpt_dialogues');
        Schema::dropIfExists('gpt_settings');
    }
};
