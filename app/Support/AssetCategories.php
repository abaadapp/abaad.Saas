<?php

namespace App\Support;

use App\Models\FixedAsset;

/**
 * تصنيفاتُ الأصول — ما صنّف به المتجرُ أصولَه، ثمّ ما نقترحه عليه.
 *
 * والقاعدةُ نفسُها التي في `PurchaseUnits`: القائمةُ تُقرأ ممّا استُعمل فعلًا
 * مرتَّبةً بكثرته، وتُذيَّل بالمقترَحات لمن لم يسجّل أصلًا بعد. ولا جدولَ
 * تصنيفاتٍ يُملأ قبل أوّل أصل — والقائمةُ المكتوبة باليد تنسى التاليَ دائمًا.
 */
final class AssetCategories
{
    /** ما يُقترح على متجرٍ لم يسجّل أصلًا بعد — اقتراحٌ لا حصر */
    public const SUGGESTED = ['أجهزة', 'أثاث', 'ثلاجات', 'مركبات', 'حواسيب', 'تجهيزات المحل', 'معدات'];

    /**
     * تصنيفاتُ هذا المتجر: المستعملةُ أوّلًا بترتيب كثرتها، ثمّ باقي المقترَحات.
     *
     * @return list<string>
     */
    public static function forBusiness(int $businessId): array
    {
        $used = FixedAsset::where('business_id', $businessId)
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->groupBy('category')
            ->orderByRaw('count(*) desc')
            ->orderBy('category')
            ->pluck('category')
            ->map(fn ($c) => trim((string) $c))
            ->filter()
            ->all();

        return array_values(array_unique([...$used, ...self::SUGGESTED]));
    }
}
