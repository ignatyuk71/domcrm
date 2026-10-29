<?php

namespace App\Modules\GptSandbox\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SandboxChat extends Model
{
    use HasUuids;

    protected $table = 'gpt_sandbox_chats';

    protected $guarded = ['id'];

    protected $hidden = ['checkout_data'];

    protected $casts = ['checkout_data' => 'encrypted:array'];

    public function turns(): HasMany
    {
        return $this->hasMany(SandboxTurn::class, 'chat_id');
    }

    public function present(): array
    {
        return [
            'id' => $this->id,
            'checkout' => $this->checkout_data,
            'turns' => $this->turns()->latest('id')->limit(30)->get()->reverse()->values()
                ->map(fn (SandboxTurn $turn) => $turn->present())->all(),
        ];
    }
}
