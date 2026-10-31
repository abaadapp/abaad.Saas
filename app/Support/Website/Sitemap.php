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
 */
final class Sitemap
{
    /** حدُّ غوغل للخريطة الواحدة خمسون ألفًا — ونبقى دونه بفسحة */
    public const MAX_PRODUCTS = 40000;

    /**
     * عناوينُ الخريطة — أو null حين لا خريطة.
     *
     * @return list<array{loc: string, alternates: array<string, string>}>|null
     */
    public static function urls(Business $business, bool $customHost = false): ?array
    {
        return match (Storefront::serves($business)) {
            Storefront::SERVES_THEME => self::theme($business),
            Storefront::SERVES_BUILT => self::built($business),
            Storefront::SERVES_SIMPLE => $customHost ? null : self::simple($business),
            default => null,
        };
    }

    /** عنوانُ الخريطة على عنوان المتجر الأصليّ — أو null إن لم يكن له عنوان */
    public static function url(Business $business): ?string
    {
        // والصفحةُ البسيطة أصلُها ما يخدمها — لا نطاقُ التاجر الخاصّ (`StoreSeo::simple`)
        $root = Storefront::serves($business) === Storefront::SERVES_SIMPLE
            ? StoreSeo::simple($business)['canonical']
            : Storefront::canonical($business->site_slug, (int) $business->id);

        return $root === null ? null : rtrim($root, '/').'/sitemap.xml';
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

    /** @return list<array{loc: string, alternates: array<string, string>}>|null */
    private static function theme(Business $business): ?array
    {
        $bid = (int) $business->id;

        if ((MarketingSettings::group($bid, 'website')['store_seo_index'] ?? '1') !== '1') {
            return null;
        }

        // [المسار، أله صفحةٌ إنجليزيّة]
        $paths = [['/', true]];

        if (StoreNav::has($bid, StoreNav::SHOP)) {
            $paths[] = ['/shop', true];
        }

        foreach (StoreNav::OPTIONAL as $page) {
            if (StoreNav::has($bid, $page, 'ar')) {
                $paths[] = ['/'.$page, StoreNav::has($bid, $page, 'en')];
            }
        }

        foreach (StoreCustomPages::visible($bid) as $page) {
            $paths[] = ['/'.StoreCustomPages::PATH.'/'.$page['slug'], true];
        }

        // والمنتجاتُ المعروضةُ وحدها — شرطُ صفحتها نفسُه (`RibbonController::shown`)
        $products = Product::where('business_id', $bid)->where('active', true)->where('published', true)
            ->orderBy('id')->limit(self::MAX_PRODUCTS)->pluck('id');

        foreach ($products as $id) {
            $paths[] = ['/p/'.$id, true];
        }

        $out = [];

        foreach ($paths as [$path, $english]) {
            $ar = StoreSeo::canonical($business, '', $path);

            if ($ar === null) {
                return null;
            }

            $alternates = $english ? ['ar' => $ar, 'en' => $ar.'?lang=en', 'x-default' => $ar] : [];
            $out[] = ['loc' => $ar, 'alternates' => $alternates];

            if ($english) {
                $out[] = ['loc' => $ar.'?lang=en', 'alternates' => $alternates];
            }
        }

        return $out;
    }
}
