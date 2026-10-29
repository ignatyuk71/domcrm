<?php

namespace App\Modules\GptSandbox\Models;

use Illuminate\Database\Eloquent\Model;

class SandboxSetting extends Model
{
    protected $table = 'gpt_sandbox_settings';

    protected $guarded = ['id'];

    protected $hidden = ['api_key'];

    protected $casts = ['api_key' => 'encrypted', 'verified_at' => 'datetime', 'meta_connection_id' => 'integer'];

    public function present(): array
    {
        return [
            'has_key' => $this->exists && $this->api_key !== '',
            'key_hint' => $this->exists ? '••••'.substr($this->api_key, -4) : null,
            'model' => $this->model ?? '',
            'meta_connection_id' => $this->meta_connection_id,
            'connection_status' => ! $this->exists ? 'missing' : ($this->connection_error ? 'error' : ($this->verified_at ? 'connected' : 'saved')),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'connection_error' => $this->connection_error,
        ];
    }

    public static function recordCheck(int $userId, #[\SensitiveParameter] string $key, ?string $error = null): void
    {
        $setting = static::where('user_id', $userId)->first();
        // Відповідь старого запиту не підтверджує новий ключ, збережений в іншій вкладці.
        if (! $setting || ! hash_equals($setting->api_key, $key)) {
            return;
        }
        $setting->connection_error = $error;
        if ($error === null) {
            $setting->verified_at = now();
        }
        $setting->save();
    }
}
