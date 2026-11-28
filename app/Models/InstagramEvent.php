<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ‏‏إشعارٌ من إنستقرام كما وصل — يُحفظ فورًا بمفتاحٍ فريد، ويُعالَج بعده (`InstagramWebhooks`).
 */
class InstagramEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
