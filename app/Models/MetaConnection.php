<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaConnection extends Model
{
    protected $fillable = [
        'name',
        'provider',
        'app_id',
        'meta_user_id',
        'meta_user_name',
        'facebook_page_id',
        'facebook_page_name',
        'instagram_account_id',
        'instagram_username',
        'business_account_id',
        'user_access_token',
        'user_token_expires_at',
        'access_token',
        'token_type',
        'token_expires_at',
        'granted_scopes',
        'verify_token',
        'webhook_secret',
        'webhook_subscribed',
        'webhook_fields',
        'is_active',
        'connected_at',
        'last_token_refresh_at',
        'last_sync_at',
        'last_error',
        'profile_payload',
        'connected_by',
    ];

    protected $casts = [
        'granted_scopes' => 'array',
        'webhook_fields' => 'array',
        'profile_payload' => 'array',
        'webhook_subscribed' => 'boolean',
        'is_active' => 'boolean',
        'connected_at' => 'datetime',
        'last_token_refresh_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'token_expires_at' => 'datetime',
        'user_token_expires_at' => 'datetime',
    ];

    public static function current(): ?self
    {
        return static::query()
            ->where('provider', 'meta')
            ->where('is_active', true)
            ->latest('id')
            ->first();
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }
}
