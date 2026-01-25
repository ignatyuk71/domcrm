<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackingSession extends Model
{
    protected $fillable = [
        'order_id',
        'packer_id',
        'closed_by',
        'started_at',
        'finished_at',
        'duration_seconds',
        'close_reason',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function packer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'packer_id');
    }
}
