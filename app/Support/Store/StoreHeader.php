<?php

namespace App\Support\Store;

use App\Models\Category;
use App\Support\MarketingSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * رأسُ متجر RIBBON — شريطُ الإعلان أعلاه، وصفُّ خيارات «المتجر» أسفلَه.
 *
 * والترويسةُ كلُّها عنصرٌ لاصقٌ واحد (`header.rb-head`): الشريطُ والشعارُ
 * والقائمةُ وخياراتُ المتجر صفوفٌ في جريانه لا عناصرُ ثابتةٌ بإزاحاتٍ
 * محسوبة — تلك تتراكب على الهاتف حين يلتفّ سطرٌ واحد.
 *
 * ═══ والمفاتيحُ في عقد النشر ═══
 *
 * أربعتُها في `StoreContent::VERSIONED`: يحفظها صاحبُها في المسوّدة، ولا
 * يراها زائرُه حتّى ينشر. وهي تُقرأ هنا من `MarketingSettings::group` —
 * أي المنشورُ على الموقع، والمسوّدةُ في المعاينة (انظر `withOverlay`).
 */
final class StoreHeader
{
    public const ANNOUNCEMENT_AR = 'store_announcement_ar';

    public const ANNOUNCEMENT_EN = 'store_announcement_en';

    public const ALIGN = 'store_announcement_align';

    public const SHORTCUTS = 'store_shop_nav_categories';

    /**
     * المحاذاةُ مكانٌ في الشاشة لا اتّجاهُ لغة.
     *
     * فاليسارُ يسارٌ على الصفحة العربيّة والإنجليزيّة معًا — هكذا سأل صاحبُ
     * المتجر — ولا تُقلَب بالاتّجاه. والقيمُ ثلاثٌ مغلقة تصير اسمَ صنفٍ في
     * CSS، فلا يبلغ الصفحةَ نصٌّ حرٌّ في `style`.
     */
    public const ALIGNS = ['left', 'center', 'right'];

    /** والأصلُ للفراغ ولكلّ ما لا يُعرف */
    public const DEFAULT_ALIGN = 'center';

    /**
     * أكثرُ ما يُختار من الفئات — ستٌّ.
     *
     * فالصفُّ يبدأ بـ«كل المنتجات» و«الأكثر مبيعًا»، وثمانيةُ أزرارٍ تسع سطرًا
     * على الشاشة الواسعة، وتُمرَّر بالإصبع على الهاتف بلا أن تصير قائمةً ثانية.
     */
    public const MAX_SHORTCUTS = 6;

    /** خيارُ «الأكثر مبيعًا» في الرابط — `‎?view=best‎`، والاسمُ لا يُقرأ منطقًا */
    public const VIEW_BEST = 'best';

    /** طولُ سطر الإعلان — سطرٌ لا فقرة */
    public const ANNOUNCEMENT_MAX = 140;

    /* ═══════════ الإعلان ═══════════ */

    /** قيمةٌ مخزّنة ← محاذاةٌ من الثلاث، والغريبُ إلى الأصل */
    public static function align(?string $raw): string
    {
        return in_array($raw, self::ALIGNS, true) ? $raw : self::DEFAULT_ALIGN;
    }

    /**
     * إعلانُ هذه اللغة — أو `null` فلا يُرسم الشريط ولا يأخذ ارتفاعًا.
     *
     * ولكلّ لغةٍ نصُّها وحدَه: الصفحةُ الإنجليزيّة لا تقع على العربيّ إن فرغ
     * الإنجليزيّ، ولا يُترجَم ما كتبه صاحبُ المحلّ. نصٌّ بلغةٍ لا يقرؤها
     * زائرُه في أعلى كلّ صفحة أسوأُ من لا نصّ.
     *
     * @return array{text: string, align: string}|null
     */
    public static function announcement(int $businessId, string $lang): ?array
    {
        $site = MarketingSettings::group($businessId, 'website');
        $text = trim((string) ($site[$lang === 'en' ? self::ANNOUNCEMENT_EN : self::ANNOUNCEMENT_AR] ?? ''));

        if ($text === '') {
            return null;
        }

        return ['text' => $text, 'align' => self::align($site[self::ALIGN] ?? null)];
    }

    /* ═══════════ صفُّ خيارات المتجر ═══════════ */

    /**
     * الفئاتُ التي اختارها بترتيبها — معرّفاتٌ كما خُزّنت، بلا فحصِ وجود.
     *
     * @return list<int>
     */
    public static function shortcutIds(int $businessId): array
    {
        return StorePage::ids(
            MarketingSettings::group($businessId, 'website')[self::SHORTCUTS] ?? '',
            self::MAX_SHORTCUTS,
        );
    }

    /**
     * أزرارُ صفّ المتجر بترتيبها: «كل المنتجات» و«الأكثر مبيعًا» ثمّ فئاتُه.
     *
     * ═══ والفئةُ تُرشَّح عند القراءة لا عند الحفظ وحده ═══
     *
     * فئةٌ اختارها ثمّ حذفها، أو أفرغها، أو معرّفٌ لا يخصّ متجرَه — كلُّها
     * تسقط هنا لأنّ `$categories` هي فئاتُ متجره التي فيها صنفٌ معروض
     * (`RibbonController::categories`). فلا زرَّ يقود إلى رفٍّ خالٍ أو إلى
     * فئةِ غيره، ولو بقي المعرّفُ في الإعداد.
     *
     * والاسمُ من تلك القائمة: `CategoryName::display` بلغة الصفحة — فلا
     * يُخزَّن للاختصار اسمٌ ثانٍ يفترق عن اسم الفئة.
     *
     * @param  Collection<int, array{id: int, name: string, count: int}>  $categories
     * @return list<array{key: string, label: string, href: string, current: bool}>
     */
    public static function shopOptions(int $businessId, Collection $categories, array $t, string $base, int $cat, bool $best): array
    {
        $byId = $categories->keyBy('id');

        $out = [
            ['key' => 'all', 'label' => $t['allProducts'], 'href' => $base.'/shop', 'current' => ! $best && $cat === 0],
            ['key' => self::VIEW_BEST, 'label' => $t['bestSellers'], 'href' => $base.'/shop?view='.self::VIEW_BEST, 'current' => $best],
        ];

        foreach (self::shortcutIds($businessId) as $id) {
            if (! $byId->has($id)) {
                continue;
            }

            $out[] = [
                'key' => 'cat-'.$id,
                'label' => (string) $byId[$id]['name'],
                'href' => $base.'/shop?cat='.$id,
                'current' => ! $best && $cat === $id,
            ];
        }

        return $out;
    }

    /* ═══════════ الحفظ ═══════════ */

    /**
     * قائمةُ الاختصارات كما تُحفظ — أو ردٌّ برسالة.
     *
     * معرّفاتٌ موجبةٌ لا غير، فريدةٌ بترتيبها، ستٌّ لا أكثر، وكلُّها فئاتٌ
     * قائمةٌ في متجره. ولا يُقصّ الزائدُ بصمت: من اختار سبعًا يُقال له.
     * والفئةُ الفارغةُ تُقبل: تظهر يوم يُعرض فيها صنف.
     */
    public static function validatedShortcuts(int $businessId, Request $request): string
    {
        $tokens = array_values(array_filter(
            array_map('trim', explode(',', (string) $request->input(self::SHORTCUTS))),
            fn ($t) => $t !== '',
        ));

        foreach ($tokens as $token) {
            if (! preg_match('/^[1-9]\d{0,18}$/', $token)) {
                throw ValidationException::withMessages([
                    self::SHORTCUTS => __('قائمة اختصارات المتجر غير صالحة — أعد اختيار الفئات.'),
                ]);
            }
        }

        $ids = array_values(array_unique(array_map('intval', $tokens)));

        if (count($ids) > self::MAX_SHORTCUTS) {
            throw ValidationException::withMessages([
                self::SHORTCUTS => __('اختر حتى :max فئات فقط.', ['max' => self::MAX_SHORTCUTS]),
            ]);
        }

        if ($ids !== [] && Category::where('business_id', $businessId)->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages([
                self::SHORTCUTS => __('فئةٌ في اختصارات المتجر ليست من فئاتك — أعد اختيارها.'),
            ]);
        }

        return implode(',', $ids);
    }
}
