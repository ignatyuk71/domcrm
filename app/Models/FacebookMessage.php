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
        'message_text',
        'attachments',
        'type',
        'is_from_customer',
        'platform',
        'is_private',
        'post_id',
        'permalink',
    ];

    protected $casts = [
        'attachments' => 'array',
        'is_from_customer' => 'boolean',
        'is_private' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
