<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ‏‏ما قالته Meta عن احتساب رسالةٍ بعينها — تصنيفٌ لا مبلغ (`App\Support\WhatsAppPrices`) */
class WhatsAppMessagePrice extends Model
{
    protected $table = 'whatsapp_message_prices';

    protected $guarded = [];

    protected $casts = ['billable' => 'boolean', 'delivered_at' => 'datetime'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
