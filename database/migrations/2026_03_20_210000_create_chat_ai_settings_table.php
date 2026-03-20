<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_ai_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(true);
            $table->string('assistant_name')->nullable();
            $table->string('model')->nullable();
            $table->unsignedSmallInteger('max_messages')->default(12);
            $table->text('reply_style')->nullable();
            $table->text('company_context')->nullable();
            $table->json('qualification_fields')->nullable();
            $table->text('handoff_rules')->nullable();
            $table->longText('knowledge_base')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_ai_settings');
    }
};
