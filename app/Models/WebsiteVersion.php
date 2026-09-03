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
}
