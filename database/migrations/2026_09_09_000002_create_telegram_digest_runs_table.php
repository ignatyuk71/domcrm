<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('telegram_digest_runs', function (Blueprint $table) {
            $table->id();
            $table->date('digest_date')->unique();
            $table->string('status', 24);
            $table->unsignedInteger('sent_parts')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_digest_runs');
    }
};
