<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * تعديلٌ واحد على فاتورة عميل — يُكتب مرّةً ولا يُعدَّل ولا يُحذف.
 *
 * حالُ الورقة قبله وبعده، والسببُ وأساسُه ومن ومتى، والمدفوعُ ساعتَها، ومستندا
 * التصحيح اللذان أصدرهما (إشعارٌ دائنٌ لما نقص، ومدينٌ لما زاد). وهو ما يُسأل
 * عنه بعد سنةٍ في مراجعة: «لماذا صار المطلوبُ مئةً وعشرين؟».
 *
 * ولفاتورةٍ صدرت: `after` حالُها بعد التصحيح — والفاتورةُ نفسُها كما صدرت.
 * فسجلٌّ يُمحى أو يُعاد كتابتُه لا يجيب. وحذفُ المتجر نفسِه يمحوه بالقاعدة
 * (`cascadeOnDelete`، `BusinessPurge`) لا من هنا.
 */
class CustomerInvoiceRevision extends Model
{
    public const DRAFT = 'draft';

    public const ISSUED = 'issued';

    public const PAID = 'paid';

    /** أساسُ التصحيح: خطأٌ في الفاتورة — م١٥٥ من اللائحة */
    public const ERROR = 'error';

    /** أساسُ التصحيح: إرجاعٌ أو إلغاءٌ جزئيّ للتوريد — م٤٨ من اللائحة */
    public const RETURN = 'return';

    public const BASES = [self::ERROR, self::RETURN];

    protected $guarded = [];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'changes' => 'array',
        'revision' => 'integer',
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

    /** الإشعارُ الدائن الذي أصدره هذا التصحيح — لما نقص */
    public function creditNote(): HasOne
    {
        return $this->hasOne(CustomerCreditNote::class, 'revision_id');
    }

    /** والإشعارُ المدين — لما زاد */
    public function debitNote(): HasOne
    {
        return $this->hasOne(CustomerDebitNote::class, 'revision_id');
    }
}
