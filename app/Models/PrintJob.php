<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ورقةٌ تنتظر صندوقَها — مهمّةُ طباعةٍ في الخادم. انظر `Support\PrintJobs`.
 */
class PrintJob extends Model
{
    /** تنتظر صندوقًا يسألها */
    public const PENDING = 'pending';

    /** حجزها صندوقٌ ويطبعها الآن */
    public const CLAIMED = 'claimed';

    /** قال الصندوق إنّها خرجت — أو طُلب حوارُ المتصفّح لها */
    public const PRINTED = 'printed';

    /** لم تخرج، أو لا يُعرف أخرجت — تُعاد بيدٍ لا وحدها */
    public const FAILED = 'failed';

    /** فاتورةُ عميل — نسخةٌ داخليّة، تُطبع حين تُسدَّد */
    public const KIND_INVOICE_INTERNAL = 'customer_invoice_internal';

    protected $guarded = [];

    protected $casts = [
        'claimed_at' => 'datetime',
        'claim_expires_at' => 'datetime',
        'printed_at' => 'datetime',
        'failed_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(PosDevice::class, 'pos_device_id');
    }
}
