<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * سندُ نقلٍ بين فرعين — بمراحله من الطلب إلى الاستلام.
 *
 * ولا تُغيَّر حالتُه هنا: الانتقالُ وحركاتُ المخزون وسجلُّ المراحل كلُّها في
 * `App\Support\BranchTransfers`، تحت قفل الصفّ. نموذجٌ يُكتب فيه `status` من
 * متحكّمٍ يقفز فوق المراحل ويُرسل سندًا لم يُعتمد.
 *
 * وليس هو `StockTransfer`: ذاك سِجلُّ النقل القديم الفوريّ، يُقرأ ولا يُكتب.
 */
class BranchTransfer extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const PREPARING = 'preparing';

    public const IN_TRANSIT = 'in_transit';

    public const DISCREPANCY = 'discrepancy';

    public const RECEIVED = 'received';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /** ما لم ينتهِ بعد — يُعدّ في التنبيهات ولوحة الفروع */
    public const OPEN = [self::PENDING, self::APPROVED, self::PREPARING, self::IN_TRANSIT, self::DISCREPANCY];

    /** ما خرج من رفّ المصدر ولم يُغلق — فيه بضاعةٌ قيدَ النقل */
    public const MOVING = [self::IN_TRANSIT, self::DISCREPANCY];

    /** ما انتهى — لا انتقالَ منه */
    public const FINAL = [self::RECEIVED, self::REJECTED, self::CANCELLED];

    /** اسمُ الحالة كما تُقرأ في الملفّ المصدَّر — والشاشةُ تقرؤه من `lib/branchTransfers.ts` */
    public const LABELS = [
        self::PENDING => 'بانتظار الموافقة',
        self::APPROVED => 'معتمد',
        self::PREPARING => 'قيد التحضير',
        self::IN_TRANSIT => 'أُرسل — بانتظار الاستلام',
        self::DISCREPANCY => 'استُلم بفرق',
        self::RECEIVED => 'اكتمل الاستلام',
        self::REJECTED => 'مرفوض',
        self::CANCELLED => 'أُلغي',
        'legacy' => 'نقل قديم',
    ];

    protected $guarded = [];

    protected $casts = [
        'self_approved' => 'boolean',
        'approved_at' => 'datetime',
        'prepared_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'received_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BranchTransferLine::class)->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(BranchOperationEvent::class, 'subject_id')
            ->where('subject_type', BranchOperationEvent::TRANSFER)->orderBy('id');
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function supplyRequest(): BelongsTo
    {
        return $this->belongsTo(BranchSupplyRequest::class, 'branch_supply_request_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function isMoving(): bool
    {
        return in_array($this->status, self::MOVING, true);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL, true);
    }
}
