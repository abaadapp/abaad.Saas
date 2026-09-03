<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * فحصُ موقع التاجر — قراءةُ صفحته كما يقرؤها محرّك البحث.
 *
 * وهذا هو الفرق بين شاشة سيو تعمل وأخرى تُملأ: **لا حقلَ هنا يُكتب فيه ما
 * يُفترض أن يكون**. الموقع خارج النظام — لا نستضيفه ولا نبني صفحاته — فأيُّ
 * «عنوان الصفحة» أو «الكلمات المفتاحية» يُكتب عندنا لا يصل إلى صفحةٍ يقرؤها
 * محرّك. وهي الحقول التي حُذفت من `MarketingSettings` لهذا السبب بعينه.
 *
 * فما يبقى صحيحًا شيءٌ واحد: أن **نفتح الصفحة ونقول ما فيها**. عنوانُها
 * الحقيقيّ، ووصفُها الحقيقيّ، وهل تمنع الفهرسة، وهل وسمُ التحليلات موجودٌ
 * فيها فعلًا. خبرٌ يُقرأ لا مقبضٌ يُدار.
 *
 * ولا يُصدَّق ما لا يُفحص: «مربوط» تعني أنّ الوسم رُئي في الصفحة، لا أنّ
 * التاجر لصق معرّفًا في حقل.
 */
class SiteAudit
{
    /** ما يُقرأ من الصفحة — أكثرُ منه تحميلٌ لصفحةٍ كاملة بلا فائدة */
    private const MAX_BYTES = 400_000;

    /**
     * يفتح الموقع ويقرأ ما فيه.
     *
     * @return array{ok:bool, error:?string, url:?string, status:?int, https:bool, html:?string}
     */
    public static function fetch(string $domain): array
    {
        $url = self::url($domain);

        if ($url === null) {
            return self::fail(__('النطاق غير مقروء.'));
        }

        try {
            $response = Http::withHeaders([
                /*
                 * ونقول من نحن.
                 *
                 * كثيرٌ من الاستضافات تردّ 403 على طلبٍ بلا هويّة، فيُقرأ
                 * الموقعُ السليم على أنّه معطوب — وهو أسوأ من ألّا يُفحص.
                 */
                'User-Agent' => 'AbaadBot/1.0 (+https://abaadapp.om)',
                'Accept' => 'text/html,application/xhtml+xml',
            ])->timeout(12)->withOptions(['allow_redirects' => ['max' => 5]])->get($url);
        } catch (\Throwable) {
            return self::fail(__('تعذّر الوصول إلى الموقع. تأكّد أنّ النطاق صحيح وأنّ الموقع يعمل.'));
        }

        return [
            'ok' => $response->successful(),
            'error' => $response->successful() ? null : __('ردّ الموقع بالرمز :code.', ['code' => $response->status()]),
            // العنوان بعد التحويلات: من كتب النطاق بلا https يُفحص ما وصل إليه فعلًا
            'url' => (string) ($response->effectiveUri() ?? $url),
            'status' => $response->status(),
            'https' => str_starts_with((string) ($response->effectiveUri() ?? $url), 'https://'),
            'html' => $response->successful() ? Str::limit($response->body(), self::MAX_BYTES, '') : null,
        ];
    }

    /** هل يُخدَم هذا الملفّ؟ — لـrobots.txt وsitemap.xml */
    public static function serves(string $domain, string $path): bool
    {
        $url = self::url($domain);

        if ($url === null) {
            return false;
        }

        try {
            return Http::timeout(8)->get(rtrim($url, '/').'/'.ltrim($path, '/'))->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * ما تقوله الصفحة عن نفسها.
     *
     * @return array{title:?string, description:?string, canonical:?string, viewport:bool, noindex:bool, lang:?string}
     */
    public static function read(?string $html): array
    {
        $html ??= '';

        return [
            'title' => self::tag($html, '/<title[^>]*>(.*?)<\/title>/is'),
            'description' => self::meta($html, 'description'),
            'canonical' => self::attr($html, '/<link[^>]+rel=["\']canonical["\'][^>]*>/i', 'href'),
            'viewport' => self::meta($html, 'viewport') !== null,
            /*
             * ومنعُ الفهرسة أخطرُ ما في الصفحة.
             *
             * سطرٌ واحد يبقى من يوم التجربة فلا تظهر الصفحةُ في البحث أبدًا —
             * والتاجر يرى موقعه يفتح فيظنّه سليمًا، ويشتري إعلاناتٍ لصفحةٍ
             * أمرَت Google بألّا تعرضها.
             */
            'noindex' => Str::contains(Str::lower((string) self::meta($html, 'robots')), 'noindex'),
            'lang' => self::attr($html, '/<html[^>]*>/i', 'lang'),
        ];
    }

    /** هل وسمُ هذا المعرّف موجودٌ في الصفحة فعلًا؟ */
    public static function carriesTag(?string $html, string $measurementId): bool
    {
        return $measurementId !== '' && Str::contains((string) $html, $measurementId);
    }

    /* ------------------------------ القراءة ------------------------------ */

    private static function tag(string $html, string $pattern): ?string
    {
        if (! preg_match($pattern, $html, $m)) {
            return null;
        }

        $value = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $value === '' ? null : $value;
    }

    private static function meta(string $html, string $name): ?string
    {
        $pattern = '/<meta[^>]+name=["\']'.preg_quote($name, '/').'["\'][^>]*>/i';

        if (! preg_match($pattern, $html, $m)) {
            return null;
        }

        return self::attribute($m[0], 'content');
    }

    private static function attr(string $html, string $pattern, string $attribute): ?string
    {
        return preg_match($pattern, $html, $m) ? self::attribute($m[0], $attribute) : null;
    }

    private static function attribute(string $tag, string $name): ?string
    {
        if (! preg_match('/'.preg_quote($name, '/').'=["\']([^"\']*)["\']/i', $tag, $m)) {
            return null;
        }

        $value = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $value === '' ? null : $value;
    }

    /** النطاق عنوانًا — والمكتوب بلا بروتوكول يُفترض https */
    public static function url(string $domain): ?string
    {
        $domain = trim($domain);

        if ($domain === '') {
            return null;
        }

        if (! preg_match('#^https?://#i', $domain)) {
            $domain = 'https://'.$domain;
        }

        return filter_var($domain, FILTER_VALIDATE_URL) === false ? null : rtrim($domain, '/');
    }

    private static function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'url' => null, 'status' => null, 'https' => false, 'html' => null];
    }
}
