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

    /** خرجت: طابعةُ الشبكة استلمتها كلَّها، أو أكّد إنسانٌ خروجَها */
    public const PRINTED = 'printed';

    /**
     * فُتح لها حوارُ طباعةٍ أو ملفّ — وفتحُه ليس ورقًا.
     *
     * المتصفّحُ لا يقول أطَبع الكاشيرُ أم ألغى الحوار. فلا يُقال «طُبعت»، ولا
     * تُعاد وحدها: تنتظر من يقول «خرجت» أو «لم تخرج».
     */
    public const REQUESTED = 'requested';

    /** لم تخرج، أو لا يُعرف أخرجت — تُعاد بيدٍ لا وحدها */
    public const FAILED = 'failed';

    /** أُغلق التجهيزُ بعد السداد وهي تنتظر — لا تعود وحدها إن فُتح من جديد */
    public const CANCELLED = 'cancelled';

    /** فاتورةُ عميل — نسخةٌ داخليّة، تُطبع حين تُسدَّد */
    public const KIND_INVOICE_INTERNAL = 'customer_invoice_internal';

    protected $guarded = [];

    protected $casts = [
        'claimed_at' => 'datetime',
        'claim_expires_at' => 'datetime',
        'printed_at' => 'datetime',
        'requested_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'failed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(PosDevice::class, 'pos_device_id');
    }
}
