<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** إشعارُ دائن — يُنقص الذمّة ولا يُعيد كتابة الفاتورة */
class CustomerCreditNote extends Model
{
    protected $guarded = [];

    /**
     * سببُ الإشعار اليدويّ — يختاره من يُصدره، ولا يُثبت وحدَه مشروعيّتَه.
     *
     * والقيمتان الأوليان قيمتا تصحيح الفاتورة نفسُهما (`error`، `return`).
     * والإشعاراتُ السابقة وإشعاراتُ إلغاء الطلبات بلا سبب.
     */
    public const ERROR = 'error';

    public const RETURN = 'return';

    public const DISCOUNT = 'discount';

    public const BASES = [self::ERROR, self::RETURN, self::DISCOUNT];

    public const BASIS_LABELS = [
        self::ERROR => 'تصحيح خطأ في المبلغ',
        self::RETURN => 'إرجاع أو إلغاء توريد',
        self::DISCOUNT => 'خصم بعد البيع',
    ];

    protected $casts = ['issued_at' => 'date'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }
}
