<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * إشعارُ دائن — يُنقص الذمّة ولا يُعيد كتابة الفاتورة.
 *
 * و`items` بنودُه حين يُسمّى ما رُدّ (`BoutiqueInvoiceDues::noteItems`)، و`basis` سببُه.
 * والإشعاراتُ السابقة وإشعاراتُ إلغاء الطلبات بلا سبب.
 */
class CustomerCreditNote extends Model
{
    /**
     * سببُ الإشعار اليدويّ — يختاره من يُصدره، ولا يُثبت وحدَه مشروعيّتَه.
     *
     * والقيمتان الأوليان قيمتا تصحيح الفاتورة نفسُهما (`error`، `return`).
     * وفي البوتيكات: الإرجاعُ وحده يحمل بنودًا تُنقص مستحقَّ البوتيك (`BoutiqueInvoiceDues::noteItems`)،
     * والخصمُ وتصحيحُ الخطأ على المتجر — لا تمسّ ما للبوتيك.
     */
    public const ERROR = 'error';

    /** بضاعةٌ رُدّت — وهو اسمُه في تصحيح الفاتورة (#195) */
    public const RETURN = 'return';

    /** خصمٌ أو تسويةُ سعرٍ بلا مرتجع — على المتجر */
    public const DISCOUNT = 'discount';

    public const BASES = [self::ERROR, self::RETURN, self::DISCOUNT];

    public const BASIS_LABELS = [
        self::ERROR => 'تصحيح خطأ في المبلغ',
        self::RETURN => 'إرجاع أو إلغاء توريد',
        self::DISCOUNT => 'خصم بعد البيع',
    ];

    protected $guarded = [];

    protected $casts = ['issued_at' => 'date', 'items' => 'array'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }
}
