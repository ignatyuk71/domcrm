<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NovaPayBalance extends Model
{
    protected $table = 'novapay_balances';

    protected $guarded = ['id'];

    protected $casts = ['amount_minor' => 'integer', 'received_at' => 'datetime'];
}
