<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * صفحةٌ في الموقع — عنوانُها ورابطُها وسيوها وأقسامُها.
 *
 * و`key` ليست `slug`: الأولى تقول ما هذه الصفحة للنظام (`home`، `shop`)
 * فيعرف أنّ المتجر يُعرض هنا وأنّ الرئيسية لا تُحذف؛ والثانية ما يكتبه
 * الزائر في المتصفّح ويبدّله التاجر متى شاء. وخلطُهما يجعل تغيير الرابط
 * يكسر معنى الصفحة.
 */
class WebsitePage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'seo' => 'array',
        'is_home' => 'boolean',
        'removable' => 'boolean',
    ];

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const HIDDEN = 'hidden';

    /** الحالات كما تُعرض — و«مخفية» ليست «مسوّدة»: هذه تعمل ولا تُدلّ عليها */
    public const STATUSES = [
        self::PUBLISHED => 'منشورة',
        self::DRAFT => 'مسوّدة',
        self::HIDDEN => 'مخفية من القائمة',
    ];

    public function website(): BelongsTo { return $this->belongsTo(Website::class); }

    public function sections(): HasMany
    {
        return $this->hasMany(WebsiteSection::class, 'page_id')->orderBy('position');
    }

    /** هل تظهر في قائمة الموقع؟ المخفية تعمل ولا يُدلّ عليها */
    public function inMenu(): bool
    {
        return $this->status === self::PUBLISHED;
    }

    /**
     * رابطٌ نظيف يبدأ بشرطةٍ ولا ينتهي بها.
     *
     * «/‎about/‎» و«about» و«/about» ثلاثةُ أشكالٍ لصفحةٍ واحدة، وأيُّها يُحفظ
     * يقرّر أيَّ رابطٍ يبني العارض. فيُوحَّد عند الكتابة لا عند القراءة.
     */
    public static function normalizeSlug(string $raw): string
    {
        $slug = trim(mb_strtolower($raw));
        $slug = preg_replace('/[^\p{Arabic}a-z0-9\-\/]+/u', '-', $slug) ?? $slug;
        $slug = trim(preg_replace('#/{2,}#', '/', $slug) ?? $slug, '/');
        $slug = trim(preg_replace('/-{2,}/', '-', $slug) ?? $slug, '-');

        return $slug === '' ? '/' : '/'.$slug;
    }
}
