<?php

namespace App\Modules\GptSandbox\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SandboxTurn extends Model
{
    use HasUlids;

    protected $table = 'gpt_sandbox_turns';

    protected $guarded = ['id'];

    protected $hidden = ['response_output', 'images', 'checkout_messages'];

    protected $casts = ['images' => 'array', 'response_output' => 'encrypted:array', 'gallery_photos' => 'array', 'checkout_messages' => 'encrypted:array'];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(SandboxChat::class, 'chat_id');
    }

    public function present(): array
    {
        return [
            'id' => $this->id,
            'request_id' => $this->request_id,
            'text' => $this->text,
            'reply' => $this->reply,
            'status' => $this->status,
            'error' => $this->error,
            'model' => $this->model,
            'checkout_messages' => $this->checkout_messages ?? [],
            'images' => collect($this->images ?? [])->map(fn ($image, $index) => [
                'name' => $image['name'],
                'url' => route('gptSandbox.image', [$this->id, $index]),
            ])->all(),
            'gallery_photos' => collect($this->gallery_photos ?? [])->map(fn ($photo) => [
                'id' => $photo['id'], 'label' => $photo['label'], 'kind' => $photo['kind'],
                'url' => route('gptSandbox.catalogImage', $photo['id']),
            ])->all(),
        ];
    }
}
