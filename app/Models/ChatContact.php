<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChatContact extends Model
{
    protected $fillable = [
        'meta_connection_id',
        'customer_id',
        'platform',
        'external_user_id',
        'external_username',
        'display_name',
        'first_name',
        'last_name',
        'avatar_path',
        'avatar_original_url',
        'profile_payload',
        'last_profile_sync_at',
    ];

    protected $casts = [
        'profile_payload' => 'array',
        'last_profile_sync_at' => 'datetime',
    ];

    public function metaConnection(): BelongsTo
    {
        return $this->belongsTo(MetaConnection::class, 'meta_connection_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function conversation(): HasOne
    {
        return $this->hasOne(ChatConversation::class, 'contact_id');
    }
}
