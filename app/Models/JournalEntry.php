<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * قيدٌ في دفتر اليومية.
 *
 * القاعدة الوحيدة التي لا يُتساهل فيها: **لا يُرحَّل قيدٌ غير متوازن**. وهي
 * هنا في النموذج لا في الشاشة، لأن القيود تأتي من ثلاثة أبواب — الشاشة،
 * والترحيل التلقائي من المبيعات والمشتريات، ومسيرة الرواتب — وحارسٌ في بابٍ
 * واحد يترك البابين الآخرين مفتوحين. ودفترٌ يقبل قيدًا مختلًّا لا يُكتشف
 * خلله إلا في ميزان المراجعة بعد شهور، ولا يُعرف حينها أيّ قيدٍ أفسده.
 */
class JournalEntry extends Model
{
    protected $guarded = [];

    protected $casts = [
        'entry_date' => 'date',
        'posted' => 'boolean',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }

    public function lines(): HasMany { return $this->hasMany(JournalLine::class)->orderBy('id'); }

    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function sourceable() { return $this->morphTo(); }

    public function totalDebit(): float
    {
        return (float) $this->lines()->sum('debit');
    }

    public function totalCredit(): float
    {
        return (float) $this->lines()->sum('credit');
    }

    /**
     * متوازنٌ ضمن نصف بيسة.
     *
     * المقارنة الحرفية بين عددين عشريّين تفشل على فروقٍ لا وجود لها في المال
     * (0.1 + 0.2 ≠ 0.3)، فيُرفض قيدٌ صحيح. والعملة ثلاث خانات، فما دون
     * النصف بيسة ليس مالًا.
     */
    public function isBalanced(): bool
    {
        return abs($this->totalDebit() - $this->totalCredit()) < 0.0005;
    }

    /**
     * الترحيل — بعده يصير القيد جزءًا من الدفتر ولا يُعدَّل.
     *
     * @throws RuntimeException إن اختلّ التوازن أو نقصت السطور
     */
    public function post(): void
    {
        if ($this->lines()->count() < 2) {
            throw new RuntimeException(__('القيد يحتاج سطرين على الأقل: مدين ودائن'));
        }

        if (! $this->isBalanced()) {
            throw new RuntimeException(__('القيد غير متوازن: المدين :d والدائن :c', [
                'd' => number_format($this->totalDebit(), 3),
                'c' => number_format($this->totalCredit(), 3),
            ]));
        }

        $this->update(['posted' => true, 'posted_at' => now()]);
    }

    /**
     * مرجعٌ متسلسل لكل نشاط — يُقرأ تحت قفلٍ على صفّ المتجر.
     *
     * ═══ ولمَ قفلٌ لا إعادةُ محاولة ═══
     *
     * `(business_id, number)` فريدٌ في القاعدة، والقراءةُ ثمّ الزيادة بلا
     * قفلٍ تجعل كاتبين في اللحظة نفسها يقرآن الرقمَ نفسَه. وقيسَ على
     * PostgreSQL حقيقيّ: ستُّ كتاباتٍ متزامنة ⇒ **واحدةٌ تمرّ وخمسٌ تسقط**
     * بـ`23505`. وكلُّ ما يمرّ من `Ledger::post` يقع فيه: بيعةُ صندوق،
     * وسندُ مورّدٍ يُعتمد، ومصروفٌ، وراتبٌ، وشيكٌ يُحصَّل — فصندوقان يبيعان
     * معًا يُردُّ أحدُهما بخطأ خادمٍ لا رسالةَ فيه.
     *
     * وجُرّبت `Contention::retry` أوّلًا — وهي جوابُ المستودع لرقم الفاتورة
     * — فردّت خمسًا من ستٍّ وأسقطت السادسة. والفرقُ أنّ قيدَ الأستاذ يُكتب
     * في معاملةٍ طويلة: رأسٌ ثمّ سطورٌ ثمّ ترحيل. فالمزاحمُ لم يُودع بعدُ
     * حين تُعاد المحاولة، فتقرأ الأعلى نفسَه وتصطدم ثانيةً — وخمسُ محاولاتٍ
     * تنفد. والفاتورةُ صفٌّ واحد يُودَع فورًا، فتنجح فيها الإعادة.
     *
     * فالقفلُ هو الجواب: يُقرأ الأعلى بعد أن يُودع من سبق — كما في
     * `PurchaseOrders::nextNumber` بالحرف. والترتيبُ ثابتٌ في النظام كلِّه —
     * صفُّ المستند أوّلًا ثمّ صفُّ المتجر — فلا ينعقد قفلان متعاكسان.
     *
     * ولا يُقرأ الأحدثُ صفًّا بل الأعلى عددًا: `orderByDesc('id')` كانت
     * تكفي ما دام الترقيمُ متتابعًا، ولا تكفي لو دخل صفٌّ بأثرٍ رجعيّ.
     */
    public static function nextNumber(int $businessId): string
    {
        return DB::transaction(function () use ($businessId) {
            Business::whereKey($businessId)->lockForUpdate()->first();

            $highest = (int) static::where('business_id', $businessId)
                ->where('number', 'like', 'JV-%')
                ->pluck('number')
                ->map(fn ($number) => (int) substr($number, 3))
                ->max();

            return 'JV-'.str_pad((string) ($highest + 1), 6, '0', STR_PAD_LEFT);
        });
    }
}
