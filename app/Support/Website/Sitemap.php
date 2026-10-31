<?php

namespace App\Support\Website;

use App\Models\Business;
use App\Models\Product;
use App\Support\MarketingSettings;
use App\Support\Store\StoreCustomPages;
use App\Support\Store\StoreNav;
use App\Support\Store\StoreSeo;
use App\Support\Storefront;

/**
 * خريطةُ موقع التاجر — ما يقرؤه غوغل ليعرف صفحاتِه كلَّها.
 *
 * ═══ ولمَ كُتبت ═══
 *
 * لم يكن لموقعٍ يبنيه أبعاد ولا لواجهةٍ خاصّة خريطةٌ قطّ: شاشةُ الفحص
 * تتركها لمن موقعُه عندنا لأنّ «ما ينقص منها ينقص منّا لا منه» (`Seo::checks`).
 * والنقصُ وقع: صفحاتٌ ومنتجاتٌ لا يصلها غوغل إلّا إن تبع رابطًا إليها.
 *
 * ═══ وما يدخلها ═══
 *
 * ما يُخدَم للزائر الآن ويأذن صاحبُه بفهرسته — لا أكثر:
 *
 *  • الموقعُ المبنيّ: صفحاتُ آخر نشرةٍ الظاهرةُ، وكلٌّ بعنوانه الأصليّ
 *    (`Published::canonicalFor`)، إلّا صفحةً أُطفئت فهرستُها.
 *  • الواجهةُ الخاصّة: الرئيسيّةُ والمتجرُ و«من نحن» و«تواصل» إن قامت،
 *    والصفحاتُ المخصّصةُ الظاهرة، والمنتجاتُ المعروضة — بلغتيها.
 *
 * ولا تدخلها السلّةُ ولا الإتمامُ ولا التأكيد (`StoreSeo::PRIVATE_PAGES`).
 * وموقعٌ أطفأ صاحبُه فهرستَه لا خريطةَ له: خريطةٌ تُعدّد صفحاتٍ تقول كلٌّ
 * منها «لا تفهرسني» تناقضٌ يقرؤه غوغل خطأً.
 *
 *  • صفحةُ المتجر البسيطة: عنوانُها الواحد كما يكتبه رأسُها (`StoreSeo::simple`).
 *    ولا خريطةَ لها على نطاق التاجر الخاصّ: لا يخدمها هناك.
 *
 * ═══ والحدُّ: خمسون ألفَ عنوانٍ في الملفّ ═══
 *
 * كانت المنتجاتُ تُقصّ عند أربعين ألفًا — وكلُّ منتجٍ في الواجهة الخاصّة
 * عنوانان بلغتيه، فالملفُّ يبلغ ثمانين ألفًا ويردّه غوغل كلَّه. فالخريطةُ
 * اليوم ملفّاتٌ لا يزيد أحدُها على `PER_FILE`، ولا منتجَ يُقصّ: متجرٌ يزيد
 * على ملفٍّ يصير `sitemap.xml` فيه فهرسًا (`sitemapindex`) يُعدّد
 * `sitemap-1.xml` وما بعده. والفهرسُ لا يُعدّد فهرسًا — البروتوكولُ يمنعه —
 * فخريطةُ المنصّة (`platform`) تُعدّد الملفّاتِ نفسَها لا فهارسَ المتاجر.
 */
final class Sitemap
{
    /**
     * أقصى عناوين الملفّ الواحد — دون حدِّ البروتوكول (خمسين ألفًا) بكثير.
     *
     * وعنوانُ الواجهة بترجمتيه نحو أربعمئة بايت، فالملفُّ نحو أربعة ميغابايت
     * — دون حدِّ الخمسين بعيدًا، ويُبنى في طلبٍ واحدٍ بلا ضغطٍ على الذاكرة.
     */
    public const PER_FILE = 10000;

    /** أقصى ما يُعدّده فهرسٌ واحد — حدُّ البروتوكول */
    public const INDEX_MAX = 50000;

    /**
     * كم ملفًّا لخريطة هذا المتجر — أو null حين لا خريطة.
     */
    public static function parts(Business $business, bool $customHost = false): ?int
    {
        if (Storefront::serves($business) === Storefront::SERVES_THEME) {
            $items = self::themeItems($business);

            return $items === null ? null : max(1, (int) ceil($items['total'] / self::themeItemsPerFile()));
        }

        $urls = self::small($business, $customHost);

        return $urls === null ? null : max(1, (int) ceil(count($urls) / self::PER_FILE));
    }

    /**
     * عناوينُ ملفٍّ من الخريطة — أو null حين لا خريطة أو لا ملفَّ بهذا الرقم.
     *
     * @return list<array{loc: string, alternates: array<string, string>}>|null
     */
    public static function urls(Business $business, bool $customHost = false, int $part = 1): ?array
    {
        if ($part < 1) {
            return null;
        }

        if (Storefront::serves($business) === Storefront::SERVES_THEME) {
            return self::theme($business, $part);
        }

        $urls = self::small($business, $customHost);
        $slice = $urls === null ? [] : array_slice($urls, ($part - 1) * self::PER_FILE, self::PER_FILE);

        // والملفُّ الأوّل قائمٌ ولو بلا صفحة — موقعٌ منشورٌ صفحاتُه كلُّها مخفيّة خريطتُه فارغة لا غائبة
        return $urls === null || ($slice === [] && $part > 1) ? null : $slice;
    }

    /** عنوانُ الخريطة على عنوان المتجر الأصليّ — أو null إن لم يكن له عنوان */
    public static function url(Business $business): ?string
    {
        $root = self::root($business);

        return $root === null ? null : $root.'/sitemap.xml';
    }

    /**
     * الملفّاتُ التي يُعدّدها فهرسٌ لهذا المتجر — `sitemap.xml` وحده، أو أجزاؤه.
     *
     * @return list<string>|null
     */
    public static function files(Business $business): ?array
    {
        $root = self::root($business);
        $parts = $root === null ? null : self::parts($business);

        if ($parts === null) {
            return null;
        }

        return $parts === 1 ? [$root.'/sitemap.xml'] : array_map(fn (int $n) => $root.'/sitemap-'.$n.'.xml', range(1, $parts));
    }

    /**
     * خريطةُ المنصّة — ملفّاتُ كلِّ متجرٍ أصلُه على مضيف أبعاد نفسِه.
     *
     * ═══ ولمَ ═══
     *
     * غوغل لا يعرف خريطةَ متجرٍ ما لم يُدَلّ عليها. ومتجرٌ يُنشر غدًا يجب أن
     * يُعرف بلا أن يرسل أحدٌ شيئًا. فملفُّ `robots.txt` على مضيف أبعاد يدلّ
     * على هذه الخريطة مرّةً واحدة، وهي تُحسب في كلّ طلب: من صار مؤهَّلًا
     * دخلها، ومن أُخفي أو لم يُنشر أو انتهت باقتُه خرج منها — بالحارس الذي
     * يحرس خريطتَه نفسَها (`parts`).
     *
     * ولا تُعدّد إلّا ما على مضيفها: فهرسٌ على `app.abaadapp.om` لا يملك أن
     * يدلّ على ملفٍّ في نطاق تاجرٍ خاصّ — ذاك يدلّ عليه `robots.txt` نطاقِه.
     *
     * @return list<string>
     */
    public static function platform(string $host): array
    {
        $out = [];

        foreach (Business::whereNotNull('site_slug')->orderBy('id')->lazy(200) as $business) {
            foreach (self::files($business) ?? [] as $file) {
                if (parse_url($file, PHP_URL_HOST) === $host) {
                    $out[] = $file;
                }
            }

            if (count($out) >= self::INDEX_MAX) {
                return array_slice($out, 0, self::INDEX_MAX);
            }
        }

        return $out;
    }

    /**
     * فهرسُ خرائط — `sitemapindex` بعناوين ملفّاته.
     *
     * @param  list<string>  $files
     */
    public static function index(array $files): string
    {
        $e = fn (string $v) => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($files as $file) {
            $out .= '  <sitemap><loc>'.$e($file).'</loc></sitemap>'."\n";
        }

        return $out.'</sitemapindex>'."\n";
    }

    /** جذرُ عنوان المتجر الأصليّ بلا شرطةٍ أخيرة — أو null */
    private static function root(Business $business): ?string
    {
        // والصفحةُ البسيطة أصلُها ما يخدمها — لا نطاقُ التاجر الخاصّ (`StoreSeo::simple`)
        $root = Storefront::serves($business) === Storefront::SERVES_SIMPLE
            ? StoreSeo::simple($business)['canonical']
            : Storefront::canonical($business->site_slug, (int) $business->id);

        return $root === null ? null : rtrim($root, '/');
    }

    /**
     * عناوينُ الموقع المبنيّ والصفحة البسيطة كلُّها — قليلةٌ بطبعها، صفحاتٌ لا منتجات.
     *
     * @return list<array{loc: string, alternates: array<string, string>}>|null
     */
    private static function small(Business $business, bool $customHost): ?array
    {
        return match (Storefront::serves($business)) {
            Storefront::SERVES_BUILT => self::built($business),
            Storefront::SERVES_SIMPLE => $customHost ? null : self::simple($business),
            default => null,
        };
    }

    /** @return list<array{loc: string, alternates: array<string, string>}>|null */
    private static function simple(Business $business): ?array
    {
        $head = StoreSeo::simple($business);

        return $head['index'] && $head['canonical'] !== null
            ? [['loc' => $head['canonical'], 'alternates' => []]]
            : null;
    }

    /**
     * المستندُ كما يُخدَم — XML بعناوينه وترجماتها.
     *
     * @param  list<array{loc: string, alternates: array<string, string>}>  $urls
     */
    public static function xml(array $urls): string
    {
        $e = fn (string $v) => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n";

        foreach ($urls as $url) {
            $out .= '  <url>'."\n".'    <loc>'.$e($url['loc']).'</loc>'."\n";

            foreach ($url['alternates'] as $lang => $href) {
                $out .= '    <xhtml:link rel="alternate" hreflang="'.$e($lang).'" href="'.$e($href).'"/>'."\n";
            }

            $out .= '  </url>'."\n";
        }

        return $out.'</urlset>'."\n";
    }

    /** @return list<array{loc: string, alternates: array<string, string>}>|null */
    private static function built(Business $business): ?array
    {
        $site = Published::forBusiness((int) $business->id);

        if ($site['state'] !== Published::OK) {
            return null;
        }

        $doc = $site['site'];
        $root = Storefront::canonical($business->site_slug, (int) $business->id);

        // والفهرسةُ تُقرأ من الرأس نفسِه الذي يُرسم — موقعًا وصفحة (`Published::head`)
        if ($root === null || str_starts_with(Published::head($doc)['robots'], 'noindex')) {
            return null;
        }

        $out = [];

        foreach ((array) ($doc['pages'] ?? []) as $page) {
            if (! is_array($page) || str_starts_with(Published::head($doc, $page)['robots'], 'noindex')) {
                continue;
            }

            $out[] = ['loc' => (string) Published::canonicalFor($root, $page), 'alternates' => []];
        }

        return $out;
    }

    /** كلُّ بندٍ في الواجهة عنوانان على الأكثر — بلغتيه — فالبنودُ في الملفّ نصفُ حدِّه */
    private static function themeItemsPerFile(): int
    {
        return intdiv(self::PER_FILE, 2);
    }

    /**
     * بنودُ الواجهة الخاصّة: صفحاتُها الثابتة كاملةً، وعددُ منتجاتها — أو null حين لا خريطة.
     *
     * والمنتجاتُ تُعدّ ولا تُحمَّل: متجرٌ بمئة ألف منتجٍ يُسأل عن عددها لا عنها.
     *
     * @return array{root: string, fixed: list<array{0: string, 1: bool}>, products: int, total: int}|null
     */
    private static function themeItems(Business $business): ?array
    {
        $bid = (int) $business->id;

        if ((MarketingSettings::group($bid, 'website')['store_seo_index'] ?? '1') !== '1') {
            return null;
        }

        $root = Storefront::canonical($business->site_slug, $bid);

        if ($root === null) {
            return null;
        }

        // [المسار، أله صفحةٌ إنجليزيّة]
        $fixed = [['/', true]];

        if (StoreNav::has($bid, StoreNav::SHOP)) {
            $fixed[] = ['/shop', true];
        }

        foreach (StoreNav::OPTIONAL as $page) {
            if (StoreNav::has($bid, $page, 'ar')) {
                $fixed[] = ['/'.$page, StoreNav::has($bid, $page, 'en')];
            }
        }

        foreach (StoreCustomPages::visible($bid) as $page) {
            $fixed[] = ['/'.StoreCustomPages::PATH.'/'.$page['slug'], true];
        }

        $products = self::shownProducts($bid)->count();

        return ['root' => $root, 'fixed' => $fixed, 'products' => $products, 'total' => count($fixed) + $products];
    }

    /** والمنتجاتُ المعروضةُ وحدها — شرطُ صفحتها نفسُه (`RibbonController::shown`) */
    private static function shownProducts(int $bid)
    {
        return Product::where('business_id', $bid)->where('active', true)->where('published', true);
    }

    /** @return list<array{loc: string, alternates: array<string, string>}>|null */
    private static function theme(Business $business, int $part): ?array
    {
        $items = self::themeItems($business);

        if ($items === null) {
            return null;
        }

        $per = self::themeItemsPerFile();
        $start = ($part - 1) * $per;

        if ($part > 1 && $start >= $items['total']) {
            return null;
        }

        // الصفحاتُ الثابتةُ أوّلًا، ثمّ المنتجاتُ بترتيبها — فكلُّ ملفٍّ قطعةٌ من سلسلةٍ واحدة
        $paths = array_slice($items['fixed'], $start, $per);
        $take = $per - count($paths);

        if ($take > 0) {
            $ids = self::shownProducts((int) $business->id)->orderBy('id')
                ->offset(max(0, $start - count($items['fixed'])))->limit($take)->pluck('id');

            foreach ($ids as $id) {
                $paths[] = ['/p/'.$id, true];
            }
        }

        $out = [];

        foreach ($paths as [$path, $english]) {
            // والعنوانُ ما تكتبه الصفحةُ نفسُها في `canonical` — بالقاعدة نفسِها، من جذرٍ سُئل مرّة
            $ar = StoreSeo::join($items['root'], '', $path);
            $alternates = $english ? ['ar' => $ar, 'en' => $ar.'?lang=en', 'x-default' => $ar] : [];
            $out[] = ['loc' => $ar, 'alternates' => $alternates];

            if ($english) {
                $out[] = ['loc' => $ar.'?lang=en', 'alternates' => $alternates];
            }
        }

        return $out;
    }
}
