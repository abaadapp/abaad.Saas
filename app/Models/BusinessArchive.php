<?php

namespace App\Models;

use App\Support\Archive\Period;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * أرشيفُ شهرٍ واحدٍ لمتجرٍ واحد — صفٌّ يصف ملفًّا.
 *
 * والحالاتُ خمسٌ لأنّ الطريق من الضغطة إلى الملفّ يمرّ بأربعة مواضعَ يقف
 * عندها: طُلب ولم يبدأ، ويُبنى الآن، وتمّ، وسقط، ومضت مدّتُه. ودمجُ
 * «بانتظار» في «قيد الإنشاء» يجعل التاجر يقرأ «قيد الإنشاء» عن وظيفةٍ لم
 * يلتقطها عاملٌ بعد — فينتظر ما لم يبدأ.
 *
 * ═══ و«جاهز» لا تُكتب إلّا بعد الملفّ ═══
 *
 * تُكتب في موضعٍ واحد (`Archive\Builder::finish`) وبعد خمسةٍ تُقاس: أُغلق
 * الـZIP، والملفُّ موجود، وحجمُه فوق الحدّ الأدنى، وبصمتُه حُسبت، والبيانُ
 * فيه. وطمأنينةٌ كاذبة أسوأ من تحذيرٍ كاذب: صفٌّ أخضرُ فوق ملفٍّ مبتور
 * يُكتشف يومَ يُطلب الأرشيفُ من المتجر — وهو آخرُ يومٍ يصلح للاكتشاف.
 */
class BusinessArchive extends Model
{
    protected $guarded = [];

    /** طُلب ولم يلتقطه عاملٌ بعد */
    public const PENDING = 'بانتظار';

    /** التقطه عاملٌ ويبنيه الآن */
    public const PROCESSING = 'قيد الإنشاء';

    /** الملفُّ على القرص، مُغلَقًا ومبصومًا */
    public const READY = 'جاهز';

    /** سقط البناء — والسببُ مكتوبٌ بلغةٍ تُقرأ */
    public const FAILED = 'فشل';

    /** مضت مدّةُ الاحتفاظ فحُذف الملفُّ وبقي الصفّ يقول إنّه كان */
    public const EXPIRED = 'منتهي';

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'file_size' => 'integer',
        'archive_version' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * أيُنزَّل الآن؟ — سؤالٌ واحدٌ يجيب عنه موضعٌ واحد.
     *
     * الشاشةُ تسأله لترسم الزرّ، والبابُ يسأله قبل أن يفتح. وفحصان لسؤالٍ
     * واحد يفترقان يوم يُبدَّل أحدُهما: يبقى الزرُّ مرسومًا بعد أن أُغلق
     * الباب، أو يُغلق البابُ والزرُّ يدعو إليه.
     */
    public function downloadable(): bool
    {
        return $this->status === self::READY
            && $this->storage_path !== null
            && ! $this->hasExpired();
    }

    /** مضت مدّتُه؟ — وفارغُ المدّة لا يمضي */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * مداه كائنًا — ومنه يُقرأ كلُّ اسمٍ وكلُّ حدّ.
     *
     * والصفُّ يحمل التاريخين والنوع، و`Period` تعرف ماذا تعني: طولَ القفزة
     * وشكلَ العنوان. فلا يُحسب في النموذج ما تحسبه هي — ولو حُسب لافترقا
     * يومَ يُضاف نوعٌ ثالث.
     */
    public function period(): Period
    {
        return Period::stored((string) $this->archive_type, $this->period_start);
    }

    /** «2026-08» أو «2026-W37» — ما يُكتب في البيان وفي اسم الملفّ */
    public function periodKey(): string
    {
        return $this->period()->key();
    }

    /** «أغسطس 2026» أو «7 – 13 سبتمبر 2026» — بلغة القارئ الآن */
    public function periodLabel(): string
    {
        return $this->period()->label();
    }

    /** أوّلُ لحظةٍ في مداه — بمنطقة التطبيق الزمنيّة */
    public function periodStart(): Carbon
    {
        return $this->period()->start();
    }

    public function isWeekly(): bool
    {
        return $this->archive_type === Period::WEEKLY;
    }
}
