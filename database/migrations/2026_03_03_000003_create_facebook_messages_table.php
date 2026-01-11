<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facebook_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->text('mid');
            $table->text('parent_id')->nullable();
            $table->text('text')->nullable();
            $table->json('attachments')->nullable();
            $table->string('type')->default('message');
            $table->boolean('is_from_customer')->default(true);
            $table->enum('platform', ['messenger', 'instagram'])->default('messenger');
            $table->boolean('is_private')->default(true);
            $table->string('post_id')->nullable();
            $table->text('permalink')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_messages');
    }
};
