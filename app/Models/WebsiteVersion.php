<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * لقطةٌ كاملة للموقع لحظةَ نشره.
 *
 * وهي الفرق بين محرّرٍ يُخيف ومحرّرٍ يُستعمل: ما دام المنشور نسخةً مجمّدة،
 * فكلُّ تعديلٍ في المسوّدة بلا أثر حتى يُنشر — والتاجر يجرّب ويحذف ويعيد بلا
 * أن يخشى على موقعٍ يعمل.
 *
 * و`payload` كلُّ شيء: الصفحات وأقسامها ومحتواها والقالب والألوان والسيو.
 * فالاستعادة كتابةُ اللقطة فوق الحيّ — لا تجميعُ فروقٍ ولا تخمين.
 */
class WebsiteVersion extends Model
{
    /**
     * محرّكُ العرض الذي نُشرت له هذه اللقطة.
     *
     * والتاريخُ واحدٌ للاثنين عمدًا: دورةُ النشر — القفلُ والرقمُ المتسلسل
     * والحمايةُ من الضغط مرّتين — تُكتب مرّةً وتُصلَح مرّةً. وجدولان
     * بالشكل نفسِه يفترقان عند أوّل إصلاحٍ يُنسى أحدُهما.
     */
    public const BUILDER = 'builder';

    public const THEME = 'theme';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'published_at' => 'datetime',
    ];

    public function website(): BelongsTo { return $this->belongsTo(Website::class); }

    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    /** ‏رقمُ النشرة التالية — متسلسلٌ لكلّ موقع لا عامّ */
    public static function nextNumber(int $websiteId): int
    {
        return ((int) static::where('website_id', $websiteId)->max('number')) + 1;
    }

    /** ورقمُ نشرةِ الواجهة الخاصّة متسلسلٌ لنشاطها — لا صفَّ لها في `websites` */
    public static function nextThemeNumber(int $businessId): int
    {
        return ((int) static::where('business_id', $businessId)
            ->where('kind', self::THEME)->max('number')) + 1;
    }
}
