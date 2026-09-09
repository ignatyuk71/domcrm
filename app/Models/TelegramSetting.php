<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramSetting extends Model
{
    public $incrementing = false;

    protected $guarded = ['id'];

    protected $hidden = ['bot_token'];

    protected $casts = [
        'bot_token' => 'encrypted',
        'enabled' => 'boolean',
        'permissions' => 'array',
        'verified_at' => 'datetime',
        'last_test_at' => 'datetime',
    ];

    public const PERMISSIONS = ['manual_test', 'warehouse_reminder', 'new_order', 'return_alert'];

    public static function current(): self
    {
        return static::find(1) ?? new static(['enabled' => false]);
    }

    public function allows(string $type): bool
    {
        return $this->enabled && $this->verified_at && $this->bot_token && $this->chat_id
            && in_array($type, self::PERMISSIONS, true)
            && ($this->permissions[$type] ?? false) === true;
    }

    public function publicSettings(): array
    {
        // Секрет ніколи не повертається у форму, навіть у замаскованому вигляді.
        return [
            'has_token' => (bool) $this->getRawOriginal('bot_token'),
            'chat_id' => $this->chat_id ?? '',
            'bot_username' => $this->bot_username,
            'bot_name' => $this->bot_name,
            'chat_title' => $this->chat_title,
            'enabled' => (bool) $this->enabled,
            'permissions' => array_replace(array_fill_keys(self::PERMISSIONS, false), $this->permissions ?? []),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'last_test_at' => $this->last_test_at?->toIso8601String(),
        ];
    }
}
