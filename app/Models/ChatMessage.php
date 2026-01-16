<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    protected $table = 'facebook_messages';

    protected $fillable = [
        'customer_id',
        'text',
        'is_from_customer',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'is_from_customer' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
