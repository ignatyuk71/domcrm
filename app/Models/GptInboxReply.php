<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Підготовлена відповідь і черга відправлення: повтор не запускає платну генерацію знову. */
class GptInboxReply extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['payload'];
    protected $casts = ['payload' => 'encrypted:array', 'provider_version' => 'integer', 'next_index' => 'integer'];
}
