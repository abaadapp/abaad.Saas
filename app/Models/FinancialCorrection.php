<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تصحيحٌ ماليّ ينتظر اعتمادَ شخصٍ ثانٍ — انظر `FinancialCorrections`.
 *
 * الحالُ تمضي في اتّجاهٍ واحد: من «معلَّق» إلى واحدةٍ من أربع (معتمَد، مرفوض،
 * مسحوب، تغيّرت فاتورتُه)، ولا تعود. فلا يُحذف الصفّ، ولا يُعاد فتحُ طلبٍ بُتّ
 * فيه — يُطلب تصحيحٌ جديد. وهو سجلُّ من طلب ومن بتّ ومتى ولماذا.
 */
class FinancialCorrection extends Model
{
    /** تصحيحُ فاتورة عميل صادرة أو مدفوعة بإشعارٍ دائنٍ أو مدين — `CustomerInvoiceEdits::apply` */
    public const INVOICE_EDIT = 'customer_invoice_edit';

    /** إلغاءُ فاتورةٍ صادرة وإصدارُ بديلتها مسودّةً لعميلٍ آخر — `CustomerInvoiceEdits::replace` */
    public const INVOICE_REPLACE = 'customer_invoice_replace';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** سحبه مقدّمُه قبل أن يُبتّ فيه */
    public const WITHDRAWN = 'withdrawn';

    /** تغيّرت الفاتورة بعد الطلب — لا يُنفَّذ على ورقةٍ غير التي رآها المعتمد */
    public const STALE = 'stale';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'summary' => 'array',
        'expected_revision' => 'integer',
        'decided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new \LogicException('طلبُ التصحيح الماليّ لا يُحذف — يُرفض أو يُسحب.');
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoiceRevision::class, 'customer_invoice_revision_id');
    }

    /** البديلةُ التي كُتبت مسودّةً حين اعتُمد الاستبدال */
    public function replacement(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'replacement_invoice_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
