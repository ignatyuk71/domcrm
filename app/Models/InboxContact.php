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
        'customer_id',
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
