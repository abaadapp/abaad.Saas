<?php

namespace App\Support\Store;

use App\Models\Product;
use App\Support\MarketingSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * «اختيارات RIBBON» — قسمٌ في الرئيسية أصنافُه بيد صاحبه وبترتيبه.
 *
 * ═══ ولمَ لا يُبنى من قسمٍ في الأصناف ═══
 *
 * قسمُ الأصناف تصنيفٌ يقرؤه الزبون في «تسوّق حسب الفئة» واختصاراتِ المتجر.
 * ولو صار بابًا خفيًّا لِما يُعرض أين — كما صار «الاضافات» لـ«أضف مع طلبك»
 * — لَنقل صاحبُ المحلّ أصنافَه بين الأقسام ليُرتّب واجهةً، فيفسد تصنيفُه.
 * فمعرّفاتُ هذا القسم له وحده (`store_picks`)، والصنفُ يبقى في قسمه، ويظهر
 * في أكثر من قسمٍ إن اختير له.
 *
 * ═══ ومطفأٌ حتّى يُرفع ═══
 *
 * ليس في الترتيب الأصليّ (`StorePage::DEFAULT_ORDER`): يظهر في المحرّر صفًّا
 * مطفأً، ويُرفع بالعين نفسِها التي تُرفع بها أخواتُه. فلا تتبدّل صفحةُ متجرٍ
 * قائمٍ يومَ النشر.
 *
 * ═══ ولمن ═══
 *
 * لمن في `storefront.ribbon_picks_businesses` وحده. ومن ليس فيها لا يُعرض
 * له الصفّ، ولا يُضمّ القسمُ إلى صفحته ولو حُفظ، ويُردّ حفظُ مفاتيحه بـ403.
 */
final class RibbonPicks
{
    /** اسمُ القسم في ترتيب الصفحة (`store_sections`) */
    public const SECTION = 'picks';

    /** أكثرُ ما يُختار — صفّان من أربع كما تتّسع الشبكة */
    public const MAX = 8;

    /** أطولُ العنوان — سطرٌ لا فقرة */
    public const TITLE_MAX = 80;

    /** المفاتيحُ التي يكتبها — وحفظُ أيٍّ منها يسأل الإذن */
    public const KEYS = ['store_picks', 'store_picks_title', 'store_picks_title_en'];

    /** أمفتوحٌ لهذا المتجر؟ — من القائمة وحدها */
    public static function allowed(int $businessId): bool
    {
        $list = array_map('intval', (array) config('storefront.ribbon_picks_businesses', []));

        return in_array($businessId, $list, true);
    }

    /** ما اختاره بترتيبه — معرّفاتٌ موجبةٌ فريدة، إلى الحدّ */
    public static function ids(int $businessId): array
    {
        return StorePage::ids(MarketingSettings::group($businessId, 'website')['store_picks'] ?? '', self::MAX);
    }

    /**
     * أصنافُ القسم — من `$shown` وحدها، بترتيبه. وفارغٌ لمن لم يُفتح له.
     *
     * @param  Collection<int, Product>  $shown
     * @return Collection<int, Product>
     */
    public static function pick(int $businessId, Collection $shown): Collection
    {
        if (! self::allowed($businessId)) {
            return collect();
        }

        return ProductList::pick($shown, self::ids($businessId));
    }

    /**
     * عنوانُ القسم بلغة الصفحة — ما كتبه لها، وإلّا اسمُ القسم في القالب
     * للّغة نفسِها. ولا تقع لغةٌ على أخرى.
     *
     * @param  array<string, string>  $t  نصوصُ القالب بلغة الصفحة
     */
    public static function title(int $businessId, string $lang, array $t): string
    {
        $key = $lang === 'en' ? 'store_picks_title_en' : 'store_picks_title';
        $written = trim((string) (MarketingSettings::group($businessId, 'website')[$key] ?? ''));

        return $written !== '' ? $written : $t['picksTitle'];
    }

    /**
     * الإذنُ والقائمة — وما سواهما (العنوانان) يمرّ بقواعد الحفظ كأيّ نصّ.
     *
     * يُسأل متى أرسل الحفظُ أحدَ المفاتيح: من ليس في القائمة يُردّ بـ403 قبل
     * أن يُكتب شيء — فالشاشةُ التي لا تعرض الصفَّ لا تحرس الباب. وما لم
     * يُرسَل لا يُكتب: حفظُ العنوان وحده لا يمسّ القائمة.
     *
     * @return array<string, string>
     */
    public static function validated(int $businessId, Request $request): array
    {
        abort_unless(self::allowed($businessId), 403);

        return $request->exists('store_picks')
            ? ['store_picks' => ProductList::validated($businessId, 'store_picks', $request->input('store_picks'), self::MAX, 'اختيارات RIBBON')]
            : [];
    }
}
