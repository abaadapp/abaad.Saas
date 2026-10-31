<?php

namespace App\Support\Website;

use App\Models\Business;
use App\Models\Website;
use App\Support\MarketingSettings;
use App\Support\Seo;
use App\Support\Store\RibbonTexts;
use App\Support\Store\StoreNav;
use App\Support\Store\StoreSeo;
use App\Support\Storefront;

/**
 * جاهزيّةُ الموقع لمحرّكات البحث — ما يقرؤه غوغل من صفحته الآن.
 *
 * ═══ ولمَ لا يفتح الموقعَ ويقرؤه ═══
 *
 * `Seo::check` تفتح موقع التاجر من خادمنا وتقرأ ما رأت — وهي صوابٌ لموقعٍ
 * عند غيرنا لا نعرف ما فيه. أمّا موقعٌ نرسمه نحن فما يُكتب في رأسه يُحسب
 * بالدوالّ نفسِها التي ترسمه (`Published::head` و`StoreSeo::head`). فالجوابُ
 * فوريٌّ بعد الحفظ، ولا يتأخّر بذاكرة نصف ساعة، ولا يقول غيرَ ما يُرسم.
 *
 * ═══ وما لا يقوله ═══
 *
 * «جاهز» تعني أنّ صفحتَه تأذن لغوغل وتدلّه — لا أنّ غوغل قرأها. الفهرسةُ
 * قرارُ غوغل ووقتُه، ويُتحقَّق منها في Search Console لا هنا. والشاشةُ تقوله
 * صريحًا تحت القائمة: من رأى خمس علاماتٍ خضراء وظنّها «أنا في غوغل» ينتظر
 * ما لم يُوعَد به.
 */
final class SearchReadiness
{
    /**
     * @return array{published: bool, indexable: bool, canonical: ?string, sitemap: ?string, items: list<array{key: string, label: string, state: string, detail: ?string, fix: ?string}>}
     */
    public static function forBusiness(Business $business): array
    {
        return $business->storefrontTheme() !== null
            ? self::theme($business)
            : self::built($business);
    }

    /* ═══════════ الموقعُ المبنيّ ═══════════ */

    private static function built(Business $business): array
    {
        $bid = (int) $business->id;
        $website = Website::where('business_id', $bid)->with('publishedVersion')->first();
        $snapshot = $website?->publishedVersion ? Publication::upgrade((array) $website->publishedVersion->payload) : null;

        // والسيو كما يُرسم: من الصفّ لمن سرى سيوُه بالحفظ، وإلّا من النشرة (`Published::forBusiness`)
        $seo = $website === null ? [] : (($snapshot === null || $website->seoIsLive()) ? Publication::seo($website) : $snapshot['seo']);
        $home = $snapshot === null ? null : Published::pageAt(Published::visible($snapshot), null);
        $head = Published::head(['seo' => $seo, 'name' => $snapshot['name'] ?? ($website?->name ?? '')], $home);

        $pageSeo = is_array($home['seo'] ?? null) ? $home['seo'] : [];
        $written = fn (string $field) => trim((string) ($pageSeo[$field] ?? '')) ?: trim((string) ($seo[$field] ?? ''));

        $published = Storefront::serves($business) === Storefront::SERVES_BUILT;
        $maintenance = $published && (bool) $website?->maintenance;
        $indexable = ! str_starts_with($head['robots'], 'noindex');
        $canonical = $published ? Published::canonicalFor(Storefront::canonical($business->site_slug, $bid), $home) : null;

        return self::report($business, $published, $maintenance, $indexable, $canonical, [
            self::title('title', 'عنوان الموقع في البحث', $written('title'), $head['title']),
            self::description('description', 'الوصف تحت العنوان', $written('description')),
        ]);
    }

    /* ═══════════ الواجهةُ الخاصّة ═══════════ */

    private static function theme(Business $business): array
    {
        $bid = (int) $business->id;
        $values = MarketingSettings::group($bid, 'website');
        $identity = MerchantData::identity($bid);
        $ar = StoreSeo::head($business, StoreNav::HOME, RibbonTexts::for('ar'), $identity);
        $en = StoreSeo::head($business, StoreNav::HOME, RibbonTexts::for('en'), $identity, lang: 'en');

        $published = Storefront::serves($business) === Storefront::SERVES_THEME;

        return self::report($business, $published, false, $ar['index'], $published ? $ar['canonical'] : null, [
            self::title('title', 'عنوان المتجر في البحث', trim((string) ($values['store_seo_title'] ?? '')), $ar['title']),
            self::description('description', 'الوصف تحت العنوان', trim((string) ($values['store_seo_desc'] ?? ''))),
            self::title('title_en', 'English search title', trim((string) ($values['store_seo_title_en'] ?? '')), $en['title']),
            self::description('description_en', 'English search description', trim((string) ($values['store_seo_desc_en'] ?? ''))),
        ]);
    }

    /* ═══════════ البنود ═══════════ */

    /**
     * @param  list<array<string, ?string>>  $texts
     */
    private static function report(Business $business, bool $published, bool $maintenance, bool $indexable, ?string $canonical, array $texts): array
    {
        $sitemap = $published && $indexable ? Sitemap::url($business) : null;
        $blocked = $canonical !== null && ! self::robotsTxtAllows((string) (parse_url($canonical, PHP_URL_PATH) ?: '/'));

        $items = [
            self::item('published', 'الموقع منشور',
                $published && ! $maintenance ? 'pass' : 'fail',
                match (true) {
                    $maintenance => __('موقعك في وضع الصيانة — غوغل يؤجّل قراءته حتّى يعود.'),
                    $published => __('يفتحه الزائر على عنوانه الآن.'),
                    default => __('موقعك غير منشور — لا يقرؤه غوغل ولا يصله زبائنك.'),
                },
                $published && ! $maintenance ? null : __('انشر موقعك من «الموقع الإلكتروني ‹ عام» — ما تحفظه هنا يبقى ويُطبَّق لحظة النشر.'),
            ),
            ...$texts,
            self::item('robots', 'السماح لمحرّكات البحث (robots)',
                $blocked ? 'fail' : ($indexable ? 'pass' : 'off'),
                match (true) {
                    $blocked => __('ملفّ robots.txt على الخادم يمنع قراءة موقعك.'),
                    $indexable => __('صفحاتك تقول لمحرّكات البحث: اقرئيني واعرضيني (index, follow).'),
                    default => __('اخترتَ إخفاء موقعك — صفحاتُه تحمل noindex فلا تُعرض في نتائج البحث.'),
                },
                $blocked ? __('راجع فريق أبعاد — المنعُ في إعداد الخادم لا في متجرك.') : null,
            ),
            self::item('canonical', 'العنوان الأصليّ (canonical)',
                $canonical !== null ? 'pass' : 'off',
                $canonical ?? __('يُكتب حين يُنشر موقعك.'),
                null,
            ),
            self::item('sitemap', 'خريطة الموقع (sitemap)',
                $sitemap !== null ? 'pass' : 'off',
                $sitemap ?? ($published ? __('لا خريطة لموقعٍ أُخفي عن محرّكات البحث.') : __('تُنشأ حين يُنشر موقعك.')),
                null,
            ),
        ];

        return [
            'published' => $published,
            'indexable' => $indexable,
            'canonical' => $canonical,
            'sitemap' => $sitemap,
            'items' => $items,
        ];
    }

    /** عنوانٌ كُتب أو تُرك — وما تُرك يُقال ما يظهر مكانه */
    private static function title(string $key, string $label, string $written, string $shown): array
    {
        $n = mb_strlen($written);

        return match (true) {
            $written === '' => self::item($key, $label, 'warn',
                __('لم تكتب عنوانًا — يظهر «:title» وحده.', ['title' => $shown]),
                __('اكتب ما تبيعه وأين، :min حرفًا فأكثر.', ['min' => Seo::TITLE_MIN])),
            $n < Seo::TITLE_MIN => self::item($key, $label, 'warn',
                __(':n حرفًا — قصيرٌ، ولا يُبحث عنه.', ['n' => $n]),
                __('اكتب ما تبيعه وأين، :min حرفًا فأكثر.', ['min' => Seo::TITLE_MIN])),
            $n > Seo::TITLE_MAX => self::item($key, $label, 'warn',
                __(':n حرفًا — غوغل يقطع ما بعد :max تقريبًا.', ['n' => $n, 'max' => Seo::TITLE_MAX]),
                __('اختصره إلى نحو :max حرفًا — Google تقطع ما زاد.', ['max' => Seo::TITLE_MAX])),
            default => self::item($key, $label, 'pass', __(':n حرفًا', ['n' => $n]), null),
        };
    }

    private static function description(string $key, string $label, string $written): array
    {
        $n = mb_strlen($written);

        return match (true) {
            $written === '' => self::item($key, $label, 'warn',
                __('لم تكتب وصفًا — غوغل يختار سطرًا من صفحتك بدلًا عنك.'),
                __('اكتب :min–:max حرفًا تقول ما تبيع ولمن.', ['min' => Seo::DESC_MIN, 'max' => Seo::DESC_MAX])),
            $n < Seo::DESC_MIN => self::item($key, $label, 'warn',
                __(':n حرفًا — قصيرٌ، وGoogle تستبدله غالبًا بجملةٍ تختارها هي.', ['n' => $n]),
                __('وسّعه إلى :min–:max حرفًا تقول ما تبيع ولمن.', ['min' => Seo::DESC_MIN, 'max' => Seo::DESC_MAX])),
            $n > Seo::DESC_MAX => self::item($key, $label, 'warn',
                __(':n حرفًا — غوغل يقطع ما بعد :max تقريبًا.', ['n' => $n, 'max' => Seo::DESC_MAX]),
                __('اختصره إلى نحو :max حرفًا.', ['max' => Seo::DESC_MAX])),
            default => self::item($key, $label, 'pass', __(':n حرفًا', ['n' => $n]), null),
        };
    }

    /**
     * أيأذن ملفُّ robots.txt على الخادم بقراءة هذا المسار؟
     *
     * مواقعُ التجّار على مضيف أبعاد (`‎/s/متجري‎`)، فملفُّ جذره ملفُّنا: سطرٌ
     * واحدٌ فيه يُخفي المتاجرَ كلَّها، ولا يراه تاجرٌ في صفحته. ويُقرأ بقدر
     * ما يحتاجه السؤال: مجموعةُ `*` أو Googlebot، و`Disallow` بادئةً.
     */
    public static function robotsTxtAllows(string $path, ?string $file = null): bool
    {
        $file ??= public_path('robots.txt');

        if (! is_file($file)) {
            return true;
        }

        $applies = false;
        $agents = false;

        foreach (preg_split('/\r\n|\r|\n/', (string) file_get_contents($file)) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*/', '', $line));

            if ($line === '') {
                continue;
            }

            [$field, $value] = array_pad(array_map('trim', explode(':', $line, 2)), 2, '');
            $field = strtolower($field);

            if ($field === 'user-agent') {
                // سطورُ user-agent المتتالية مجموعةٌ واحدة
                $applies = ($agents && $applies) || $value === '*' || stripos($value, 'googlebot') !== false;
                $agents = true;

                continue;
            }

            $agents = false;

            if ($applies && $field === 'disallow' && $value !== '' && str_starts_with($path, $value)) {
                return false;
            }
        }

        return true;
    }

    private static function item(string $key, string $label, string $state, ?string $detail, ?string $fix): array
    {
        return [
            'key' => $key,
            'label' => __($label),
            // pass | warn | fail | off
            'state' => $state,
            'detail' => $detail,
            'fix' => in_array($state, ['pass', 'off'], true) ? null : $fix,
        ];
    }
}
