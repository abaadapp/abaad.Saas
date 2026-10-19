<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** بندُ فاتورةٍ — لقطةٌ لا إحالة: اسمٌ وثمنٌ ونسبةُ ضريبةٍ محفوظة */
class CustomerInvoiceItem extends Model
{
    protected $guarded = [];

    /**
     * `details` لقطةُ ما اختير مع البند بلا مال — مقاسُه وخياراتُه وملاحظتُه
     * وإضافاتُه المشمولةُ في ثمنه واسمُه بلغتيه. انظر `InvoiceLineDetails`.
     */
    protected $casts = [
        'details' => 'array',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * الإضافةُ التي كانها هذا البند — إشارةٌ لا مصدر.
     *
     * الورقةُ تُقرأ من اللقطة (`description` و`unit_price`)، وهذه تبقى لمن
     * أراد أن يجمع: إضافةٌ حُذفت بعدها يُفرَّغ معرّفُها ويبقى البند.
     */
    public function addon(): BelongsTo
    {
        return $this->belongsTo(Addon::class);
    }

    /** الصنفُ الذي بيعت معه هذه الإضافة — للعرض تحته، والمالُ في سطرها هي */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_item_id');
    }
}
