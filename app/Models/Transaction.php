<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    // القيد يتبع مصروفه: يُخفى معه ويعود معه بمرجعه نفسه
    use SoftDeletes;

    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:3', 'occurred_at' => 'datetime'];

    /**
     * بيعةٌ من نقطة البيع — النوع الوحيد الذي لا يُسجَّل يدويًّا.
     *
     * وهو المفتاح الذي تُقرأ به المبيعات في التقارير. كانت تُقرأ بـ
     * `type = 'دخل'`، وهي خانةٌ تجمع البيعةَ وتعويضَ التأمين وإيداعَ المالك —
     * فيقرأ التاجر «مبيعات الشهر» وفيها مالٌ لم يبع مقابله شيئًا.
     */
    public const SALE = 'sale';

    /** قيدُها في دفتر الأستاذ — إن رُحّلت */
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }

    /** الطلب الذي ولّد هذه الحركة — إن كانت بيعة */
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }

    /**
     * المبيعات وحدها — لا كلُّ ما دخل، ولا ما أُلغي منها.
     *
     * والإلغاء يُقرأ من حالة الفاتورة لا من عمودٍ يُكتب هنا عند الإلغاء:
     * عمودٌ ثانٍ يعني موضعين يجب أن يتّفقا، وأوّلُ مسارِ إلغاءٍ يُنسى فيه
     * يجعل الشاشة تقول إيرادًا والفواتير تقول ملغاة. والاشتقاق لا يُنسى.
     *
     * وكان `reportSummary` يستثني الملغى (عبر `Order::sold`) و`financeStats`
     * لا يستثنيه: بطاقتان في شاشتين عن الفترة نفسها بجوابين — ١٠٠٠ هنا و١٠٠
     * هناك. فصار التعريف واحدًا: الملغاة ليست بيعًا في أيّ شاشة.
     *
     * والصفّ يبقى في الجدول ويُقرأ في «الحركة المالية» موسومًا: التاريخ
     * المالي لا يُمحى بإلغاء — يُلغى أثرُه لا أثرُه في السجلّ.
     */
    public function scopeSales($query)
    {
        return $query->where('kind', self::SALE)->notCancelled();
    }

    /**
     * ما لم تُلغَ فاتورته — والحركة التي لا فاتورة لها تمرّ.
     *
     * تعريفٌ واحد يقرأ منه كلُّ مجموعٍ في القسم، فلا تقول شاشةٌ إنّ الدخل ألف
     * وتقول التي بجوارها مئة.
     */
    public function scopeNotCancelled($query)
    {
        return $query->whereDoesntHave('order', fn ($q) => $q->where('status', Order::CANCELLED));
    }

    /** هل أُلغيت فاتورتها؟ — تُوسم في الشاشة ولا تُحذف */
    public function isCancelled(): bool
    {
        return $this->order_id !== null && $this->order?->status === Order::CANCELLED;
    }

    /**
     * مرجعٌ متسلسل لكل نشاط.
     *
     * كان `'TRX-' . random_int(60000, 99999)`: أربعون ألف قيمة بلا قيد فريد،
     * فاحتمال أن تحمل معاملتان المرجع نفسه يبلغ النصف بعد ٢٣٥ معاملة. وهو
     * عطبُ ترقيم الفواتير نفسه الذي أُصلح هناك وبقي هنا — وفي دفترٍ ماليّ
     * مرجعان متطابقان يعنيان أن التاجر لا يعرف أيّ صفٍّ يُصحّح.
     */
    public static function nextReference(int $businessId): string
    {
        $last = static::where('business_id', $businessId)
            ->where('reference', 'like', 'TRX-%')
            ->orderByDesc('id')
            ->value('reference');

        $n = $last ? (int) substr($last, 4) : 0;

        return 'TRX-'.str_pad((string) ($n + 1), 6, '0', STR_PAD_LEFT);
    }
    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
}
