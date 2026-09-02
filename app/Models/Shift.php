<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * وردية صندوقٍ مضت.
 *
 * الميزة رُفعت من نقطة البيع بطلب صاحب النظام: لا تُفتح وردية بعد اليوم ولا
 * تُقفَل ولا تحبس بيعة. وبقي النموذج والجدول لسببٍ واحد — **ما كُتب لا
 * يُمحى**: صفوفٌ سُجّلت يوم كانت الميزة تعمل، تُقرأ في النسخة الاحتياطية
 * وتُستعاد معها (انظر `BackupService`).
 *
 * فلا يُكتب فيه شيءٌ جديد. ومن أراد رفعه كلّيًّا فالطريق هجرةٌ مقصودة تُسقط
 * الجدول — لا حذفٌ عرضيّ مع شيفرةٍ ماتت.
 */
class Shift extends Model
{
    public const OPEN = 'مفتوحة';

    public const CLOSED = 'مغلقة';

    /* كيف انتهت الوردية — والفرق لا يُعرف إلا في الأولى */
    public const BY_COUNT = 'counted';

    public const BY_SYSTEM = 'auto';

    public const BY_ADMIN = 'admin';

    protected $guarded = [];

    protected $casts = [
        'opened_at' => 'datetime', 'closed_at' => 'datetime',
        'opening_balance' => 'decimal:3', 'cash_sales' => 'decimal:3', 'card_sales' => 'decimal:3',
        'returns' => 'decimal:3', 'expenses' => 'decimal:3', 'expected_balance' => 'decimal:3',
        'actual_balance' => 'decimal:3', 'difference' => 'decimal:3',
    ];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }

    public function openedBy(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }

    public function closedBy(): BelongsTo { return $this->belongsTo(User::class, 'closed_by'); }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    /** أُقفلت بلا أن يعدّ أحدٌ الدرج — فرقُها مجهول لا صفر */
    public function closedWithoutCount(): bool
    {
        return ! $this->isOpen() && $this->closed_kind !== null && $this->closed_kind !== self::BY_COUNT;
    }

    /**
     * وردية طال فتحُها فوق ما يحتمله يومُ عمل.
     *
     * السقف بالساعات لا «قبل منتصف الليل»: وردية تبدأ العاشرة مساءً وتنتهي
     * الثانية فجرًا شرعيّة، وقاعدةٌ تُقفل عند اليوم التقويميّ تقطعها في
     * منتصفها.
     */
    public function isStale(int $maxHours): bool
    {
        return $this->isOpen()
            && $this->opened_at !== null
            && $this->opened_at->diffInHours(now()) >= $maxHours;
    }
}
