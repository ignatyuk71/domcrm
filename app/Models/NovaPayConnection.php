<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NovaPayConnection extends Model
{
    protected $table = 'novapay_connections';

    protected $guarded = ['id'];

    protected $hidden = ['login', 'refresh_token', 'public_certificate', 'access_token'];

    protected $casts = [
        'refresh_token' => 'encrypted',
        'public_certificate' => 'encrypted',
        'access_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'requires_auth' => 'boolean',
    ];

    public function accounts(): HasMany
    {
        return $this->hasMany(NovaPayAccount::class, 'connection_id');
    }
}
