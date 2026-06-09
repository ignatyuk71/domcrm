<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_connection_id')->constrained('meta_connections')->cascadeOnDelete();
            $table->string('channel');                 // facebook | instagram
            $table->string('external_id');             // PSID (FB) / IGSID (IG)
            $table->string('name')->nullable();
            $table->text('profile_pic')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->timestamps();

            $table->unique(['meta_connection_id', 'channel', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_contacts');
    }
};
