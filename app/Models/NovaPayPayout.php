<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NovaPayPayout extends Model
{
    protected $table = 'novapay_payouts';

    protected $guarded = ['id'];

    protected $casts = ['active' => 'boolean', 'gross_minor' => 'integer', 'fee_minor' => 'integer', 'net_minor' => 'integer'];
}
