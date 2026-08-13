<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * حسابٌ في شجرة الحسابات.
 *
 * الطبيعة (`normal_side`) محفوظةٌ لا مشتقّة من النوع: مجمّع الإهلاك أصلٌ
 * طبيعته دائنة، ومردودات المبيعات إيرادٌ طبيعته مدينة. والاشتقاق يخطئ فيهما
 * فيقلب إشارة الرصيد في ميزان المراجعة.
 */
class Account extends Model
{
    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];

    /**
     * الحساب مفتوحٌ ما لم يُغلق — والقيمة هنا لا في القاعدة وحدها.
     *
     * قيمة العمود الافتراضية لا تصل إلى النموذج المُنشأ في الذاكرة: يبقى
     * `active` فارغًا حتى يُقرأ من جديد. فحسابٌ يُنشأ ثمّ يُرحَّل إليه في
     * الطلب نفسه كان يُردّ بـ«حسابٌ مغلق» وهو مفتوح.
     */
    protected $attributes = ['active' => true];

    /** الأنواع الخمسة وطبيعتها الافتراضية */
    public const TYPES = [
        'أصل' => 'debit',
        'خصم' => 'credit',
        'حقوق ملكية' => 'credit',
        'إيراد' => 'credit',
        'مصروف' => 'debit',
    ];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }

    public function parent(): BelongsTo { return $this->belongsTo(self::class, 'parent_id'); }

    public function children(): HasMany { return $this->hasMany(self::class, 'parent_id'); }

    public function lines(): HasMany { return $this->hasMany(JournalLine::class); }

    /**
     * حسابٌ له أبناء لا يُرحَّل إليه.
     *
     * الترحيل إلى أبٍ وإلى ابنه معًا يُضاعف المبلغ في أي تقرير يجمع الشجرة:
     * يُقرأ مرّةً في الابن ومرّةً في الأب. فالأوراق وحدها تقبل القيد.
     */
    public function isPostable(): bool
    {
        return $this->active && ! $this->children()->exists();
    }

    /**
     * رصيد الحساب بإشارة طبيعته — موجبٌ يعني «كما يُتوقَّع منه».
     *
     * الفرق المجرّد (مدين ناقص دائن) يجعل كل إيرادٍ سالبًا في الشاشة، فيقرأ
     * التاجر مبيعاته بإشارة ناقص ويظنّها خسارة.
     */
    public function balance(): float
    {
        $sums = $this->lines()->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();
        $diff = (float) ($sums->d ?? 0) - (float) ($sums->c ?? 0);

        return $this->normal_side === 'credit' ? -$diff : $diff;
    }
}
