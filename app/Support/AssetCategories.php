<?php

namespace App\Support;

use App\Models\FixedAsset;
use App\Models\Setting;

/**
 * تصنيفاتُ الأصول — ما صنّف به المتجرُ أصولَه، ثمّ ما نقترحه عليه.
 *
 * والقاعدةُ نفسُها التي في `PurchaseUnits`: القائمةُ تُقرأ ممّا استُعمل فعلًا
 * مرتَّبةً بكثرته، وتُذيَّل بالمقترَحات لمن لم يسجّل أصلًا بعد. ولا جدولَ
 * تصنيفاتٍ يُملأ قبل أوّل أصل — والقائمةُ المكتوبة باليد تنسى التاليَ دائمًا.
 *
 * وما يُرفع منها يُرفع ولا يُمحى: أصلٌ سُجّل «أثاثًا» يبقى أثاثًا في بطاقته
 * وفي تقاريره — والحذفُ رأيٌ في القائمة لا إعادةُ كتابةٍ لما مضى. وما استُعمل
 * عاد: تصنيفٌ رُفع ثمّ سُجّل به أصلٌ جديد يُرفع من المرفوعات.
 */
final class AssetCategories
{
    /** مفتاحُ ما رفعه التاجرُ من قائمته */
    public const HIDDEN = 'asset_categories_hidden';

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

        // ‏والمطروحُ يُطرح آخرًا: تصنيفٌ رُفع وما زال على أصولٍ قديمة لا يعود به
        return array_values(array_diff(
            array_unique([...$used, ...self::SUGGESTED]),
            self::hidden($businessId),
        ));
    }

    /**
     * ما رفعه التاجرُ من قائمته.
     *
     * @return list<string>
     */
    public static function hidden(int $businessId): array
    {
        $saved = json_decode((string) Setting::where('business_id', $businessId)
            ->where('key', self::HIDDEN)->value('value'), true);

        if (! is_array($saved)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($c) => trim((string) $c), $saved)));
    }

    /** يرفع تصنيفًا من قائمة المتجر — ولا يمسّ أصلًا سُجّل به */
    public static function hide(int $businessId, string $category): void
    {
        $category = trim($category);

        if ($category === '') {
            return;
        }

        self::remember($businessId, [...self::hidden($businessId), $category]);
    }

    /**
     * وما استُعمل عاد.
     *
     * @param  array<int, string|null>  $categories
     */
    public static function unhide(int $businessId, array $categories): void
    {
        $hidden = self::hidden($businessId);
        $used = array_filter(array_map(fn ($c) => trim((string) $c), $categories));
        $rest = array_values(array_diff($hidden, $used));

        if (count($rest) !== count($hidden)) {
            self::remember($businessId, $rest);
        }
    }

    /** @param  array<int, string>  $categories */
    private static function remember(int $businessId, array $categories): void
    {
        Setting::updateOrCreate(
            ['business_id' => $businessId, 'key' => self::HIDDEN],
            ['value' => json_encode(array_values(array_unique($categories)), JSON_UNESCAPED_UNICODE)],
        );
    }
}
