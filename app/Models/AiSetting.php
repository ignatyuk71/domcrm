<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiSetting extends Model
{
    public const DEFAULT_OPENAI_MODEL = 'gpt-6.1-sol';

    protected $fillable = [
        'meta_connection_id',
        'enabled',
        'model',
        'debounce_seconds',
        'api_key',
        'provider',
        'openai_api_key',
        'openai_model',
        'system_prompt',
        'catalog_mode',
        'operator_pause_hours',
        'operator_pause_minutes',
        'follow_up_hours',
        'comment_settings',
        'schedule',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'schedule' => 'array',
        'comment_settings' => 'array',
        'api_key' => 'encrypted',
        'openai_api_key' => 'encrypted',
        'debounce_seconds' => 'integer',
        'operator_pause_hours' => 'integer',
        'operator_pause_minutes' => 'integer',
        'follow_up_hours' => 'integer',
    ];

    protected $hidden = ['api_key', 'openai_api_key'];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(MetaConnection::class, 'meta_connection_id');
    }

    /** Глобальний рядок (ключ API + модель). */
    public static function global(): self
    {
        return static::firstOrCreate(['meta_connection_id' => null]);
    }

    /** Налаштування магазину. */
    public static function forConnection(int $connectionId): self
    {
        return static::firstOrCreate(['meta_connection_id' => $connectionId]);
    }
}
