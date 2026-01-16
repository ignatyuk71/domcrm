<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacebookMessage extends Model
{
    protected $fillable = [
        'customer_id',
        'mid',
        'parent_id',
        'text',
        'attachments',
        'type',
        'is_from_customer',
        'platform',
        'is_private',
        'post_id',
        'permalink',
        'sent_at',
        'status',
        'is_read',
    ];

    protected $casts = [
        'attachments' => 'array',
        'is_from_customer' => 'boolean',
        'is_private' => 'boolean',
        'sent_at' => 'datetime',
        'is_read' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
