<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedFile extends Model
{
    protected $fillable = [
        'filename',
        'path',
        'url',
        'type',
    ];
}
