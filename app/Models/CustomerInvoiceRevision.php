<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تعديلٌ واحد على فاتورة عميل — يُكتب مرّةً ولا يُعدَّل ولا يُحذف.
 *
 * الورقةُ قبله وبعده، والسببُ ومن ومتى، والمدفوعُ ساعتَها، وقيدا العكس
 * والترحيل. وهو ما يُسأل عنه بعد سنةٍ في مراجعة: «لماذا صارت مئةً وعشرين؟».
 * فسجلٌّ يُمحى أو يُعاد كتابتُه لا يجيب. وحذفُ المتجر نفسِه يمحوه بالقاعدة
 * (`cascadeOnDelete`، `BusinessPurge`) لا من هنا.
 */
class CustomerInvoiceRevision extends Model
{
    public const DRAFT = 'draft';

    public const ISSUED = 'issued';

    public const PAID = 'paid';

    protected $guarded = [];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'changes' => 'array',
        'revision' => 'integer',
        'reversal_date' => 'date',
        'entry_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('سجلُّ تعديل الفاتورة لا يُعدَّل — التعديلُ التالي سجلٌّ جديد.');
        });

        static::deleting(function () {
            throw new \LogicException('سجلُّ تعديل الفاتورة لا يُحذف.');
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
