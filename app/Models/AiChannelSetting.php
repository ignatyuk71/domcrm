<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiChannelSetting extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['gpt_api_key'];

    protected $casts = ['claude_enabled' => 'boolean', 'gpt_enabled' => 'boolean', 'gpt_api_key' => 'encrypted'];

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], ['claude_enabled' => true, 'gpt_enabled' => false, 'default_provider' => 'claude']);
    }

    public function present(): array
    {
        $enabled = $this->default_provider === 'gpt' ? $this->gpt_enabled : $this->claude_enabled;

        return ['agent' => $enabled ? $this->default_provider : 'off', 'gpt_model' => $this->gpt_model ?? '',
            'has_gpt_key' => (bool) $this->gpt_api_key, 'gpt_key_hint' => $this->gpt_api_key ? '••••'.substr($this->gpt_api_key, -4) : null];
    }
}
