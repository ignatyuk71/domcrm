<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NovaPaySyncRun extends Model
{
    protected $table = 'novapay_sync_runs';

    protected $guarded = ['id'];

    protected $casts = [
        'date_from' => 'date', 'date_to' => 'date',
        'started_at' => 'datetime', 'finished_at' => 'datetime', 'succeeded_at' => 'datetime',
    ];

    public function setDateFromAttribute($value): void
    {
        $this->attributes['date_from'] = $value === null ? null : \Carbon\CarbonImmutable::parse($value)->toDateString();
    }

    public function setDateToAttribute($value): void
    {
        $this->attributes['date_to'] = $value === null ? null : \Carbon\CarbonImmutable::parse($value)->toDateString();
    }
}
