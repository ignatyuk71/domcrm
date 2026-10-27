<?php

namespace App\Modules\Gpt\Models;

use Illuminate\Database\Eloquent\Model;

class GptSetting extends Model
{
    protected $fillable = ['enabled', 'api_key', 'model', 'store_policies', 'version'];

    protected $hidden = ['api_key'];

    protected $casts = ['enabled' => 'boolean', 'api_key' => 'encrypted', 'version' => 'integer'];

    public static function current(): self
    {
        return static::find(1) ?? new static(['enabled' => false, 'version' => 0]);
    }

    public function key(): string
    {
        return trim((string) ($this->api_key ?: config('gpt-drafts.api_key')));
    }

    public function modelName(): string
    {
        return trim((string) ($this->model ?: config('gpt-drafts.model')));
    }

    public function publicData(): array
    {
        return [
            'enabled' => (bool) $this->enabled,
            'mode' => 'drafts',
            'has_api_key' => $this->key() !== '',
            'model' => $this->modelName(),
            'store_policies' => $this->store_policies ?? '',
            'version' => (int) $this->version,
        ];
    }
}
