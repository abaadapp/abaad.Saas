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

    /**
     * ترتيبُ أزرار صفّ المتجر كلِّها — «كل المنتجات» و«الأكثر مبيعًا» والمجموعاتُ
     * والفئات — كما يريده صاحبُه: `all,best,col:{slug},cat:{id}` بترتيبها.
     *
     * والفراغُ الترتيبُ القديمُ حرفًا بحرف. وزرٌّ ليس في القائمة (مجموعةٌ أُنشئت
     * بعدها، فئةٌ اختيرت بعدها) يُلحق في آخرها بترتيبه الأصليّ. و«كل المنتجات»
     * لا يغيب: هو بابُ البضاعة كلِّها، فإن غاب عن القائمة صار أوّلَها.
     */
    public const ROW = 'store_shop_row';

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
     * ═══ ومجموعاتُه بعد «الأكثر مبيعًا» وقبل الفئات ═══
     *
     * ما أظهره منها وأذِن به في الصفّ (`in_row`)، بترتيبه — باسمها بلغة
     * الصفحة (`StoreCollections::title`). ومتجرٌ لم يكتب مجموعةً صفُّه كما
     * كان حرفًا بحرف.
     *
     * @param  Collection<int, array{id: int, name: string, count: int}>  $categories
     * @param  list<array<string, mixed>>  $collections  المجموعاتُ الظاهرة — `StoreCollections::visible`
     * @return list<array{key: string, label: string, href: string, current: bool}>
     */
    public static function shopOptions(int $businessId, Collection $categories, array $t, string $base, int $cat, bool $best, array $collections = [], ?string $collection = null, string $lang = 'ar'): array
    {
        $byId = $categories->keyBy('id');
        $inCollection = $collection !== null && $collection !== '';

        $out = [
            ['key' => 'all', 'label' => $t['allProducts'], 'href' => $base.'/shop', 'current' => ! $best && $cat === 0 && ! $inCollection],
            ['key' => self::VIEW_BEST, 'label' => $t['bestSellers'], 'href' => $base.'/shop?view='.self::VIEW_BEST, 'current' => $best],
        ];

        foreach ($collections as $c) {
            if (! ($c['in_row'] ?? true)) {
                continue;
            }

            $out[] = [
                'key' => 'col-'.$c['slug'],
                'label' => StoreCollections::title($c, $lang),
                'href' => $base.'/shop?'.StoreCollections::QUERY.'='.$c['slug'],
                'current' => ! $best && $collection === $c['slug'],
            ];
        }

        foreach (self::shortcutIds($businessId) as $id) {
            if (! $byId->has($id)) {
                continue;
            }

            $out[] = [
                'key' => 'cat-'.$id,
                'label' => (string) $byId[$id]['name'],
                'href' => $base.'/shop?cat='.$id,
                'current' => ! $best && ! $inCollection && $cat === $id,
            ];
        }

        return self::ordered($out, self::rowTokens($businessId));
    }

    /**
     * الترتيبُ المحفوظ — رموزٌ كما كُتبت، بلا فحصِ وجود.
     *
     * @return list<string>
     */
    public static function rowTokens(int $businessId): array
    {
        $raw = trim((string) (MarketingSettings::group($businessId, 'website')[self::ROW] ?? ''));

        return $raw === '' ? [] : array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)), fn ($t) => $t !== '')));
    }

    /** رمزُ الزرّ في الترتيب — `col-eid` ← `col:eid` */
    public static function token(string $key): string
    {
        return match (true) {
            str_starts_with($key, 'col-') => 'col:'.substr($key, 4),
            str_starts_with($key, 'cat-') => 'cat:'.substr($key, 4),
            default => $key,
        };
    }

    /**
     * الأزرارُ بترتيب صاحبها — وما لم يذكره بعدها بترتيبه الأصليّ.
     *
     * @param  list<array{key: string, label: string, href: string, current: bool}>  $options
     * @param  list<string>  $tokens
     * @return list<array{key: string, label: string, href: string, current: bool}>
     */
    private static function ordered(array $options, array $tokens): array
    {
        if ($tokens === []) {
            return $options;
        }

        // و«كل المنتجات» إن غاب عن الترتيب صار أوّلَه — لا يُخفى بابُ البضاعة كلِّها
        if (! in_array('all', $tokens, true)) {
            array_unshift($tokens, 'all');
        }

        $rank = array_flip($tokens);
        $keyed = [];

        foreach ($options as $i => $o) {
            $keyed[] = [$rank[self::token($o['key'])] ?? count($tokens) + $i, $o];
        }

        usort($keyed, fn ($a, $b) => $a[0] <=> $b[0]);

        return array_column($keyed, 1);
    }

    /* ═══════════ الحفظ ═══════════ */

    /**
     * ترتيبُ صفّ المتجر كما يُحفظ — أو ردٌّ برسالة.
     *
     * رموزٌ معروفةٌ وحدها، فريدةٌ بترتيبها: «كل المنتجات» و«الأكثر مبيعًا»،
     * ومجموعاتُه بأسمائها (ما يُحفظ معها في الطلب نفسِه، وإلّا المسوّدة)،
     * وفئاتُه بمعرّفاتها — من متجره وحده. و«كل المنتجات» فيه دائمًا.
     *
     * @param  list<array<string, mixed>>  $collections
     */
    public static function validatedRow(int $businessId, mixed $raw, array $collections): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }

        $bad = fn () => throw ValidationException::withMessages([
            self::ROW => __('ترتيب شريط المتجر غير صالح — أعد ترتيبه من المحرّر.'),
        ]);

        if (! is_string($raw) || strlen($raw) > 2000) {
            $bad();
        }

        $tokens = array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($t) => $t !== ''));
        $slugs = array_map(fn (array $c) => $c['slug'], $collections);
        $cats = [];

        if (count($tokens) !== count(array_unique($tokens)) || count($tokens) > 2 + StoreCollections::MAX + self::MAX_SHORTCUTS) {
            $bad();
        }

        /*
         * ═══ والإشارةُ القديمةُ تسقط ولا تردّ الحفظ ═══
         *
         * مجموعةٌ حُذفت أو تبدّل رابطُها بعد أن رُتّب الشريط، وفئةٌ حُذفت،
         * تبقى أسماؤها في الترتيب المحفوظ. وكان الحفظُ يُردّ من أجلها — وهو
         * يُرسَل مع المجموعات في صفّها — فلا يُحفظ تعديلُ مجموعةٍ حتّى يُعاد
         * ترتيبُ شريطٍ لم يلمسه. فما لا يقود إلى شيءٍ في متجره يسقط وحده،
         * ومعرّفُ فئةِ غيره كذلك: يسقط ولا يُحفظ. والرمزُ الغريبُ يُردّ.
         */
        $kept = [];

        foreach ($tokens as $token) {
            if (in_array($token, ['all', self::VIEW_BEST], true)) {
                $kept[] = $token;

                continue;
            }

            if (str_starts_with($token, 'col:') && preg_match(StoreCollections::SLUG, substr($token, 4)) === 1) {
                if (in_array(substr($token, 4), $slugs, true)) {
                    $kept[] = $token;
                }

                continue;
            }

            if (preg_match('/^cat:([1-9]\d{0,18})$/', $token, $m) === 1) {
                $cats[] = (int) $m[1];
                $kept[] = $token;

                continue;
            }

            $bad();
        }

        if ($cats !== []) {
            $mine = Category::where('business_id', $businessId)->whereIn('id', $cats)->pluck('id')->map(fn ($id) => 'cat:'.$id)->all();
            $kept = array_values(array_filter($kept, fn (string $t) => ! str_starts_with($t, 'cat:') || in_array($t, $mine, true)));
        }

        if (! in_array('all', $kept, true)) {
            throw ValidationException::withMessages([
                self::ROW => __('«كل المنتجات» يبقى في شريط المتجر — رتّبه ولا تحذفه.'),
            ]);
        }

        return implode(',', $kept);
    }

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
