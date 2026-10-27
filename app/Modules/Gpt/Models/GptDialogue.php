<?php

namespace App\Modules\Gpt\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GptDialogue extends Model
{
    protected $fillable = ['created_by', 'title', 'memory'];

    protected $casts = ['memory' => 'array'];

    public function runs(): HasMany
    {
        return $this->hasMany(GptRun::class);
    }
}
