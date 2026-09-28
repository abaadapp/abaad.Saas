<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * نيّةُ شراءٍ من الموقع — محفوظةٌ حتّى يُصدّق البنك.
 *
 * ولا طلبَ قبلها: الطلبُ يخصم المخزون، ولو كُتب قبل الدفع لَخصم كلُّ زائرٍ
 * فتح صفحةَ البطاقة ثمّ أغلقها باقةً من الرفّ.
 */
class StorePaymentIntent extends Model
{
    /** أُرسلت إلى البوّابة ولم يصل جوابُها */
    public const PENDING = 'pending';

    /** وصل المال */
    public const PAID = 'paid';

    /** ردّته البوّابة */
    public const FAILED = 'failed';

    /* ═══════════ حالُ الردّ ═══════════ */

    /** طُلب الردُّ ولم يُجب بعد — يُكتب قبل الإرسال لا بعده */
    public const REFUND_PENDING = 'pending';

    /** قبلته البوّابة */
    public const REFUND_SENT = 'sent';

    /** ردّته البوّابة — والسببُ في `refund_error` */
    public const REFUND_FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'amount' => 'decimal:3',
        'paid_at' => 'datetime',
        'expires_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    /** انقضت مهلةُ صفحة الدفع؟ — والفراغُ لا يُقرأ انقضاءً */
    public function expired(?Carbon $at = null): bool
    {
        return $this->expires_at !== null && $this->expires_at->lt($at ?? now());
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * أوصل مالُه ولم يجد طلبًا يحمله؟
     *
     * وهي الحالةُ التي تُوقظ صاحبَ المحلّ: نفد الصنفُ بين لحظةِ الدفع
     * ولحظةِ التصديق، فرُدّ إنشاءُ الطلب والمالُ مقبوض. ولا تُحلّ في كود —
     * يردّ المالَ أو يجهّز بديلًا، وكلاهما قرارُه.
     */
    public function strayPayment(): bool
    {
        return $this->status === self::PAID && $this->order_id === null;
    }

    /**
     * مالٌ وصل، ولا طلبَ له، ولا رُدّ — وهي وحدَها ما يُوقظ صاحبَ المحلّ.
     *
     * فما رُدّ تلقائيًّا لا يُوقظه: المسألةُ أُغلقت، والزبونُ استعاد مالَه.
     * وجرسٌ يرنّ لما انتهى يُدرَّب الناظرُ على تخطّيه.
     */
    public function needsAttention(): bool
    {
        return $this->strayPayment() && $this->refund_status !== self::REFUND_SENT;
    }
}
