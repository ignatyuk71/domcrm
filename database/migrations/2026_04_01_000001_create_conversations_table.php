<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('conversations')) {
            return;
        }

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->enum('platform', ['messenger', 'instagram'])->default('messenger');
            $table->text('last_message_text')->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->unsignedInteger('unread_count')->default(0);
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamps();

            $table->unique(['customer_id', 'platform']);
        });

        $hasSentAt = Schema::hasColumn('facebook_messages', 'sent_at');
        $timeColumn = $hasSentAt ? 'COALESCE(fm.sent_at, fm.created_at)' : 'fm.created_at';
        $latestTime = $hasSentAt ? 'MAX(COALESCE(sent_at, created_at))' : 'MAX(created_at)';

        DB::statement(
            "INSERT INTO conversations (customer_id, platform, last_message_text, last_message_at, unread_count, status, created_at, updated_at)
             SELECT fm.customer_id,
                    fm.platform,
                    fm.text,
                    {$timeColumn},
                    0,
                    'open',
                    NOW(),
                    NOW()
             FROM facebook_messages fm
             INNER JOIN (
                SELECT customer_id, platform, {$latestTime} AS last_time
                FROM facebook_messages
                GROUP BY customer_id, platform
             ) latest
             ON latest.customer_id = fm.customer_id
                AND latest.platform = fm.platform
                AND {$timeColumn} = latest.last_time"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
