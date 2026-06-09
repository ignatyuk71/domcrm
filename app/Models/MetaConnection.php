<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaConnection extends Model
{
    protected $fillable = [
        'page_id',
        'page_name',
        'page_access_token',
        'ig_account_id',
        'ig_username',
        'fb_user_id',
        'scopes',
        'webhook_subscribed',
        'status',
        'last_error',
        'connected_by',
    ];

    protected $casts = [
        'page_access_token' => 'encrypted',
        'scopes' => 'array',
        'webhook_subscribed' => 'boolean',
    ];

    /** Не віддаємо токен у JSON/масиви. */
    protected $hidden = [
        'page_access_token',
    ];

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
