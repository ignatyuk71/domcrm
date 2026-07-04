<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InboxContact extends Model
{
    protected $fillable = [
        'meta_connection_id',
        'channel',
        'external_id',
        'name',
        'profile_pic',
        'profile_pic_checked_at',
        'customer_id',
    ];

    protected $casts = [
        'profile_pic_checked_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(MetaConnection::class, 'meta_connection_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function conversation(): HasOne
    {
        return $this->hasOne(InboxConversation::class);
    }
}
