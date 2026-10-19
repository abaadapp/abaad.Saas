<?php

namespace App\Support\Store;

use App\Support\MarketingSettings;
use Illuminate\Validation\ValidationException;

/**
 * صفحاتُ متجر RIBBON المخصّصة — «خدماتنا» و«المناسبات» وما يكتبه صاحبُها.
 *
 * ═══ ولمَ ليست رابطًا وحده ═══
 *
 * رابطٌ في القائمة إلى صفحةٍ فارغة وعدٌ لا يُوفى. فالصفحةُ عنوانٌ ونبذةٌ
 * وصورةٌ رئيسيّة، وأقسامٌ من صورةٍ ونصٍّ بترتيب صاحبها، وزرٌّ اختياريٌّ إلى
 * وجهةٍ في متجره (`StoreTarget`). ورابطُها `‎/page/{slug}‎` — والاسمُ المختصر
 * يُكتب مرّةً حين تُنشأ ولا يتبدّل بإعادة التسمية، فرابطٌ وُزّع أمسِ يفتح اليوم.
 *
 * ═══ وأين تُحفظ ═══
 *
 * في إعدادات الموقع (`store_custom_pages`) نصًّا بصيغة JSON، داخل عقد المسوّدة
 * والنشر (`StoreContent::VERSIONED`) كالمجموعات والشريط: تُحفظ فلا يراها زبونٌ
 * حتّى تُنشر، وتُعايَن قبل الحفظ، وتُستعاد مع لقطتها. وجدولٌ لها كان سيُكتب
 * حيًّا لحظةَ الحفظ — خارجَ النشر. ولا شيءَ يُنشأ من تلقاء نفسه: فارغةٌ لكلّ
 * متجرٍ حتّى يكتبها صاحبُه.
 *
 * ═══ واللغة ═══
 *
 * العنوانُ اسمٌ يُضغط في القائمة: بلغة الصفحة، وإلّا باللغة الأخرى — قاعدةُ
 * أسماء المجموعات. وما سواه جملٌ يكتبها لزبونه (النبذةُ والأقسامُ والزرّ):
 * لكلّ لغةٍ نصُّها وحدها، ولا تقع إحداهما على الأخرى. والصورُ للّغتين.
 *
 * ═══ ولا وسمَ يُرسم ═══
 *
 * النصوصُ تُحفظ كما كُتبت وتُطبع مُهرَّبةً (`{{ }}`)، وأسطرُها أسطرٌ
 * (`white-space: pre-line`). فلا HTML ولا `<script>` يبلغ صفحةَ زبون، والصورُ
 * من مجلّد المتجر وحده (`StoreMedia::owned`).
 */
final class StoreCustomPages
{
    public const KEY = 'store_custom_pages';

    /** مقطعُ الرابط تحت عنوان المتجر — `‎/page/{slug}‎` */
    public const PATH = 'page';

    /** أكثرُها — قائمةٌ رئيسيّة تُقرأ في سطر، لا فهرسُ موقع */
    public const MAX = 12;

    /** أكثرُ أقسام الصفحة الواحدة */
    public const MAX_SECTIONS = 8;

    public const TITLE_MAX = 80;

    public const INTRO_MAX = 800;

    public const SECTION_TITLE_MAX = 80;

    public const BODY_MAX = 2000;

    public const CTA_MAX = 30;

    /** أطولُ ما يُقبل من النصّ المحفوظ — حمايةٌ قبل أن يُفكّ */
    public const RAW_MAX = 200000;

    /* ═══════════ القراءة ═══════════ */

    /**
     * الصفحاتُ كما حُفظت — بترتيبها، وما لا يُقرأ يسقط بصمت.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(int $businessId): array
    {
        return self::fromJson((string) (MarketingSettings::group($businessId, 'website')[self::KEY] ?? ''));
    }

    /** @return list<array<string, mixed>> */
    public static function visible(int $businessId): array
    {
        return array_values(array_filter(self::all($businessId), fn (array $p) => $p['visible']));
    }

    /** صفحةٌ ظاهرةٌ بالاسم المختصر — من هذا المتجر وحده — أو `null` */
    public static function find(int $businessId, string $slug): ?array
    {
        foreach (self::visible($businessId) as $p) {
            if ($p['slug'] === $slug) {
                return $p;
            }
        }

        return null;
    }

    /** ولو أخفاها صاحبُها — للمعاينة وحدها */
    public static function findAny(int $businessId, string $slug): ?array
    {
        foreach (self::all($businessId) as $p) {
            if ($p['slug'] === $slug) {
                return $p;
            }
        }

        return null;
    }

    /** عنوانُها بلغة الصفحة — وإلّا باللغة الأخرى */
    public static function title(array $p, string $lang): string
    {
        $mine = $lang === 'en' ? $p['title_en'] : $p['title_ar'];

        return $mine !== '' ? $mine : ($lang === 'en' ? $p['title_ar'] : $p['title_en']);
    }

    /**
     * الصفحةُ كما تُرسم بلغتها — عنوانُها ونبذتُها وصورُها وأقسامُها وزرُّها.
     *
     * وقسمٌ لا صورةَ له ولا نصَّ بهذه اللغة لا يُرسم فيها: إطارٌ فارغٌ بين
     * قسمين أسوأُ من غيابه. والزرُّ يُرسم إن كُتب نصُّه بلغة الصفحة وكانت
     * وجهتُه تُفتح الآن.
     *
     * @return array{slug: string, title: string, intro: string, image: array<string, mixed>|null, sections: list<array<string, mixed>>, cta: array{label: string, href: string}|null, visible: bool}
     */
    public static function view(int $businessId, array $p, string $lang, string $base): array
    {
        $sfx = $lang === 'en' ? '_en' : '_ar';
        $sections = [];

        foreach ($p['sections'] as $s) {
            $image = self::image($businessId, $s['image'], $s['fit']);

            if ($image === null && $s['title'.$sfx] === '' && $s['body'.$sfx] === '') {
                continue;
            }

            $sections[] = ['title' => $s['title'.$sfx], 'body' => $s['body'.$sfx], 'image' => $image];
        }

        $cta = null;

        if ($p['cta'] !== null && $p['cta']['label'.$sfx] !== '') {
            $href = StoreTarget::hrefs($businessId, $base, $lang, [$p['cta']['target']])[0];
            $cta = $href === null ? null : ['label' => $p['cta']['label'.$sfx], 'href' => $href];
        }

        return [
            'slug' => $p['slug'],
            'title' => self::title($p, $lang),
            'intro' => $p['intro'.$sfx],
            'image' => self::image($businessId, $p['image'], $p['fit']),
            'sections' => $sections,
            'cta' => $cta,
            'visible' => $p['visible'],
        ];
    }

    /** @return array{url: string, fit: string, w: int|null, h: int|null}|null */
    private static function image(int $businessId, string $url, string $fit): ?array
    {
        if ($url === '') {
            return null;
        }

        $size = StoreMedia::size($businessId, $url);

        return ['url' => $url, 'fit' => StoreMedia::fit($fit), 'w' => $size['w'] ?? null, 'h' => $size['h'] ?? null];
    }

    /* ═══════════ الحفظ ═══════════ */

    /**
     * ما أرسلته الشاشة ← نصُّ JSON يُحفظ — أو رفضٌ برسالة.
     *
     * @param  list<array<string, mixed>>  $collections  المجموعاتُ التي يُقاس عليها زرُّ الصفحة —
     *                                                   ما يُحفظ معها في الطلب نفسِه، وإلّا المسوّدة
     */
    public static function validated(int $businessId, mixed $raw, array $collections): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }

        if (! is_string($raw) || strlen($raw) > self::RAW_MAX) {
            self::refuse(__('الصفحات المخصّصة غير صالحة — أعد فتح المحرّر واحفظ من جديد.'));
        }

        $list = json_decode($raw, true);

        if (! is_array($list) || ! array_is_list($list)) {
            self::refuse(__('الصفحات المخصّصة غير صالحة — أعد فتح المحرّر واحفظ من جديد.'));
        }

        if (count($list) > self::MAX) {
            self::refuse(__('أنشئ حتى :max صفحة فقط.', ['max' => self::MAX]));
        }

        /*
         * والأسماءُ المختصرة تُجمع أوّلًا — فزرُّ صفحةٍ يقود إلى صفحةٍ بعدها
         * في القائمة نفسِها يُقبل.
         */
        $slugs = [];

        foreach ($list as $i => $p) {
            $slug = is_array($p) ? self::text($p['slug'] ?? '') : '';

            if (preg_match(StoreCollections::SLUG, $slug) !== 1) {
                self::refuse(__('رابط الصفحة :n غير صالح — حروفٌ إنجليزية صغيرة وأرقام وشرطة.', ['n' => $i + 1]));
            }

            if (in_array($slug, $slugs, true)) {
                self::refuse(__('رابطان لصفحتين بالاسم نفسه «:slug» — لكلّ صفحةٍ رابطها.', ['slug' => $slug]));
            }

            $slugs[] = $slug;
        }

        $collectionSlugs = array_map(fn (array $c) => $c['slug'], $collections);
        $out = [];

        foreach ($list as $i => $p) {
            $n = $i + 1;
            $row = ['slug' => $slugs[$i]];

            foreach (['title' => self::TITLE_MAX, 'intro' => self::INTRO_MAX] as $field => $max) {
                foreach (['_ar', '_en'] as $sfx) {
                    $row[$field.$sfx] = self::text($p[$field.$sfx] ?? '');

                    if (mb_strlen($row[$field.$sfx]) > $max) {
                        self::refuse(__('نصّ الصفحة :n أطول من :max حرفًا.', ['n' => $n, 'max' => $max]));
                    }
                }
            }

            if ($row['title_ar'] === '' && $row['title_en'] === '') {
                self::refuse(__('اكتب عنوانًا للصفحة :n — بالعربية أو بالإنجليزية.', ['n' => $n]));
            }

            [$row['image'], $row['fit']] = self::checkedImage($businessId, $p, __('صورة الصفحة :n ليست من صور متجرك — ارفعها من المحرّر.', ['n' => $n]));

            $sections = $p['sections'] ?? [];

            if (! is_array($sections) || ! array_is_list($sections)) {
                self::refuse(__('أقسام الصفحة :n غير صالحة — أعد فتح المحرّر واحفظ من جديد.', ['n' => $n]));
            }

            if (count($sections) > self::MAX_SECTIONS) {
                self::refuse(__('أضف حتى :max أقسام في الصفحة :n.', ['max' => self::MAX_SECTIONS, 'n' => $n]));
            }

            $row['sections'] = [];

            foreach ($sections as $j => $s) {
                $m = $j + 1;

                if (! is_array($s)) {
                    self::refuse(__('أقسام الصفحة :n غير صالحة — أعد فتح المحرّر واحفظ من جديد.', ['n' => $n]));
                }

                $section = [];

                foreach (['title' => self::SECTION_TITLE_MAX, 'body' => self::BODY_MAX] as $field => $max) {
                    foreach (['_ar', '_en'] as $sfx) {
                        $section[$field.$sfx] = self::text($s[$field.$sfx] ?? '');

                        if (mb_strlen($section[$field.$sfx]) > $max) {
                            self::refuse(__('نصّ القسم :m في الصفحة :n أطول من :max حرفًا.', ['m' => $m, 'n' => $n, 'max' => $max]));
                        }
                    }
                }

                [$section['image'], $section['fit']] = self::checkedImage($businessId, $s, __('صورة القسم :m في الصفحة :n ليست من صور متجرك — ارفعها من المحرّر.', ['m' => $m, 'n' => $n]));

                if ($section['image'] === '' && implode('', array_intersect_key($section, array_flip(['title_ar', 'title_en', 'body_ar', 'body_en']))) === '') {
                    self::refuse(__('القسم :m في الصفحة :n فارغ — أضف صورةً أو نصًّا، أو احذفه.', ['m' => $m, 'n' => $n]));
                }

                $row['sections'][] = $section;
            }

            $row['cta'] = self::checkedCta($businessId, $p['cta'] ?? null, $collectionSlugs, $slugs, $n);
            $row['visible'] = filter_var($p['visible'] ?? true, FILTER_VALIDATE_BOOL);
            $out[] = $row;
        }

        return $out === [] ? '' : json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * صورةٌ وطريقةُ عرضها — من مجلّد المتجر وحده.
     *
     * @return array{0: string, 1: string}
     */
    private static function checkedImage(int $businessId, array $from, string $foreign): array
    {
        $image = self::text($from['image'] ?? '');
        $fit = self::text($from['fit'] ?? '');

        if (! StoreMedia::owned($businessId, $image)) {
            self::refuse($foreign);
        }

        if ($fit !== '' && ! in_array($fit, StoreMedia::FITS, true)) {
            self::refuse(__('طريقة عرض الصورة غير صالحة — اختر «كاملة» أو «ملء الإطار».'));
        }

        return [$image, $fit];
    }

    /**
     * زرُّ الصفحة — نصٌّ لكلّ لغة ووجهةٌ في متجره، أو `null` فلا زرّ.
     *
     * @param  list<string>  $collections
     * @param  list<string>  $pages
     */
    private static function checkedCta(int $businessId, mixed $cta, array $collections, array $pages, int $n): ?array
    {
        if (! is_array($cta)) {
            return null;
        }

        $out = ['label_ar' => self::text($cta['label_ar'] ?? ''), 'label_en' => self::text($cta['label_en'] ?? '')];

        if ($out['label_ar'] === '' && $out['label_en'] === '') {
            return null;
        }

        foreach (['label_ar', 'label_en'] as $k) {
            if (mb_strlen($out[$k]) > self::CTA_MAX) {
                self::refuse(__('نصّ زرّ الصفحة :n أطول من :max حرفًا.', ['n' => $n, 'max' => self::CTA_MAX]));
            }
        }

        [$target] = StoreTarget::checked($businessId, $cta['target'] ?? null, $collections, $pages);

        if ($target === null) {
            self::refuse(__('ما يفتحه زرّ الصفحة :n ليس في متجرك — اختر وجهته من جديد.', ['n' => $n]));
        }

        return $out + ['target' => $target];
    }

    /* ═══════════ أدوات ═══════════ */

    /**
     * نصٌّ محفوظ ← قائمة — للقراءة، ولمن يقيس عليها ما يُحفظ معها.
     *
     * @return list<array<string, mixed>>
     */
    public static function fromJson(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $list = json_decode($raw, true);

        if (! is_array($list) || ! array_is_list($list)) {
            return [];
        }

        $out = [];
        $seen = [];
        $fit = fn (array $from) => in_array($from['fit'] ?? null, StoreMedia::FITS, true) ? $from['fit'] : '';

        foreach (array_slice($list, 0, self::MAX) as $p) {
            if (! is_array($p)) {
                continue;
            }

            $slug = self::text($p['slug'] ?? '');

            if (preg_match(StoreCollections::SLUG, $slug) !== 1 || isset($seen[$slug])) {
                continue;
            }

            $row = ['slug' => $slug];

            foreach (['title', 'intro'] as $field) {
                foreach (['_ar', '_en'] as $sfx) {
                    $row[$field.$sfx] = self::text($p[$field.$sfx] ?? '');
                }
            }

            if ($row['title_ar'] === '' && $row['title_en'] === '') {
                continue;
            }

            $seen[$slug] = true;
            $row['image'] = self::text($p['image'] ?? '');
            $row['fit'] = $fit($p);
            $row['sections'] = [];

            foreach (array_slice(is_array($p['sections'] ?? null) ? array_values($p['sections']) : [], 0, self::MAX_SECTIONS) as $s) {
                if (! is_array($s)) {
                    continue;
                }

                $section = ['image' => self::text($s['image'] ?? ''), 'fit' => $fit($s)];

                foreach (['title', 'body'] as $field) {
                    foreach (['_ar', '_en'] as $sfx) {
                        $section[$field.$sfx] = self::text($s[$field.$sfx] ?? '');
                    }
                }

                $row['sections'][] = $section;
            }

            $cta = is_array($p['cta'] ?? null) ? $p['cta'] : null;
            $target = $cta === null ? null : StoreTarget::parse($cta['target'] ?? null);
            $row['cta'] = $target === null ? null : [
                'label_ar' => self::text($cta['label_ar'] ?? ''),
                'label_en' => self::text($cta['label_en'] ?? ''),
                'target' => $target,
            ];
            $row['visible'] = (bool) ($p['visible'] ?? true);
            $out[] = $row;
        }

        return $out;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim(str_replace("\r\n", "\n", (string) $value)) : '';
    }

    private static function refuse(string $message): never
    {
        throw ValidationException::withMessages([self::KEY => $message]);
    }
}
