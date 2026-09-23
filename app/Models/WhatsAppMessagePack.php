<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * حزمةُ رسائلَ يشتريها المتجر — طلبُها وفاتورتُها ومصيرُها.
 *
 * ودورةُ حياتها في `WhatsAppPacks` لا هنا: النموذجُ صفٌّ في جدول، والقواعدُ
 * التي تحكم متى يُعتمد ومتى يُضاف الرصيد تُكتب في موضعٍ واحدٍ يُسأل.
 */
class WhatsAppMessagePack extends Model
{
    protected $table = 'whatsapp_message_packs';

    protected $guarded = [];

    protected $casts = ['amount' => 'decimal:3', 'decided_at' => 'datetime'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
