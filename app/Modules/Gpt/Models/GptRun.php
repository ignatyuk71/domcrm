<?php

namespace App\Modules\Gpt\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GptRun extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'result' => 'array', 'evidence' => 'array', 'usage' => 'array',
        'started_at' => 'datetime', 'finished_at' => 'datetime',
    ];

    public function dialogue(): BelongsTo
    {
        return $this->belongsTo(GptDialogue::class, 'gpt_dialogue_id');
    }

    public function publicData(): array
    {
        return $this->only(['id', 'gpt_dialogue_id', 'request_id', 'status', 'input_message',
            'model', 'prompt_version', 'result', 'evidence', 'usage', 'error_code', 'error_message',
            'started_at', 'finished_at', 'created_at']);
    }
}
