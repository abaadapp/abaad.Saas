<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'amount' => 'decimal:3',
        'paid_at' => 'datetime',
    ];

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
}
