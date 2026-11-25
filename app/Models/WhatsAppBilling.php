<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ‏‏على من تُحتسب رسائلُ حساب واتساب هذا لهذا المتجر — انظر `App\Support\WhatsAppBillingLock`. بلا بطاقةٍ ولا رقم وسيلة دفع */
class WhatsAppBilling extends Model
{
    protected $table = 'whatsapp_billings';

    protected $guarded = [];

    protected $casts = [
        'facts' => 'array',
        'evidence' => 'array',
        'requested_at' => 'datetime',
        'proven_at' => 'datetime',
        'stopped_at' => 'datetime',
        'checked_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
