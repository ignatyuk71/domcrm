<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('chat_conversations', 'thread_kind')) {
            Schema::table('chat_conversations', function (Blueprint $table) {
                $table->string('thread_kind', 32)
                    ->default('direct')
                    ->after('status');
            });
        }

        if (!$this->hasIndex('chat_conversations', 'chat_conversations_thread_kind_idx')) {
            Schema::table('chat_conversations', function (Blueprint $table) {
                $table->index('thread_kind', 'chat_conversations_thread_kind_idx');
            });
        }

        if (!$this->hasIndex('chat_conversations', 'chat_conversations_contact_idx')) {
            Schema::table('chat_conversations', function (Blueprint $table) {
                $table->index('contact_id', 'chat_conversations_contact_idx');
            });
        }

        if ($this->hasIndex('chat_conversations', 'chat_conversations_contact_unique')) {
            Schema::table('chat_conversations', function (Blueprint $table) {
                $table->dropUnique('chat_conversations_contact_unique');
            });
        }

        if (!$this->hasIndex('chat_conversations', 'chat_conversations_contact_thread_status_idx')) {
            Schema::table('chat_conversations', function (Blueprint $table) {
                $table->index(
                    ['contact_id', 'thread_kind', 'status'],
                    'chat_conversations_contact_thread_status_idx'
                );
            });
        }

        if (!Schema::hasColumn('meta_connections', 'last_webhook_at')) {
            Schema::table('meta_connections', function (Blueprint $table) {
                $table->timestamp('last_webhook_at')->nullable()->after('last_sync_at');
            });
        }

        if (!Schema::hasColumn('meta_connections', 'last_webhook_platform')) {
            Schema::table('meta_connections', function (Blueprint $table) {
                $table->string('last_webhook_platform', 32)->nullable()->after('last_webhook_at');
            });
        }

        DB::table('chat_conversations')
            ->select(['id', 'meta'])
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $meta = json_decode((string) ($row->meta ?? 'null'), true);
                    $kind = data_get($meta, 'origin_context.kind') === 'comment'
                        ? 'comment'
                        : 'direct';

                    DB::table('chat_conversations')
                        ->where('id', $row->id)
                        ->update(['thread_kind' => $kind]);
                }
            });
    }

    public function down(): void
    {
        if (
            Schema::hasColumn('meta_connections', 'last_webhook_at')
            || Schema::hasColumn('meta_connections', 'last_webhook_platform')
        ) {
            Schema::table('meta_connections', function (Blueprint $table) {
                $dropColumns = array_values(array_filter([
                    Schema::hasColumn('meta_connections', 'last_webhook_at') ? 'last_webhook_at' : null,
                    Schema::hasColumn('meta_connections', 'last_webhook_platform') ? 'last_webhook_platform' : null,
                ]));

                if ($dropColumns !== []) {
                    $table->dropColumn($dropColumns);
                }
            });
        }

        if ($this->hasIndex('chat_conversations', 'chat_conversations_contact_thread_status_idx')) {
            Schema::table('chat_conversations', function (Blueprint $table) {
                $table->dropIndex('chat_conversations_contact_thread_status_idx');
            });
        }

        if ($this->hasIndex('chat_conversations', 'chat_conversations_contact_idx')) {
            Schema::table('chat_conversations', function (Blueprint $table) {
                $table->dropIndex('chat_conversations_contact_idx');
            });
        }

        if ($this->hasIndex('chat_conversations', 'chat_conversations_thread_kind_idx')) {
            Schema::table('chat_conversations', function (Blueprint $table) {
                $table->dropIndex('chat_conversations_thread_kind_idx');
            });
        }

        if (Schema::hasColumn('chat_conversations', 'thread_kind')) {
            Schema::table('chat_conversations', function (Blueprint $table) {
                $table->dropColumn('thread_kind');
            });
        }

        if (!$this->hasIndex('chat_conversations', 'chat_conversations_contact_unique')) {
            Schema::table('chat_conversations', function (Blueprint $table) {
                $table->unique('contact_id', 'chat_conversations_contact_unique');
            });
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $result = DB::select('PRAGMA index_list("' . $table . '")');

            foreach ($result as $row) {
                if (($row->name ?? null) === $index) {
                    return true;
                }
            }

            return false;
        }

        $result = DB::select('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?', [$index]);

        return $result !== [];
    }
};
