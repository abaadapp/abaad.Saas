<?php

namespace App\Support\Store;

use App\Models\Product;
use App\Support\MarketingSettings;
use Illuminate\Validation\ValidationException;

/**
 * صفحاتُ متجر RIBBON المخصّصة — «خدماتنا» و«المناسبات» وما يكتبه صاحبُها.
 *
 * ═══ صفحةٌ من وحداتٍ يرتّبها ═══
 *
 * عنوانٌ ونبذة، ثمّ وحداتٌ بترتيب صاحبها (`BLOCKS`): عنوانٌ فرعيّ، فقرة،
 * صورة، معرضُ صور، قسمٌ من نصٍّ وصورة، منتجاتٌ يختارها، مجموعةٌ قائمة، زرّ،
 * عرضٌ ترويجيّ. ولا يُطلب منه رابطٌ ولا صورةٌ ولا نصٌّ لم يختره: صفحةُ كتابةٍ
 * بلا صورة، وصفحةُ صورٍ بلا نصّ، وصفحةُ منتجاتٍ بلا مجموعةٍ تُنشأ لها.
 *
 * ═══ ونوعُ الصفحة ما يُعرض له من الوحدات (`KINDS`) ═══
 *
 * «صفحة كتابة» تعرض العنوانَ والفقرةَ والزرّ، و«صفحة صور» الصورةَ والمعرض…
 * و«محتوى متنوّع» كلَّها. فالنوعُ يُبسّط ما يراه ولا يغيّر شكلَ ما حُفظ —
 * وتبديلُه يُبقي ما يناسب النوعَ الجديد، والمحرّرُ يسأل قبل أن يُسقط غيرَه.
 *
 * ═══ ورابطُها لا يتبدّل ═══
 *
 * `‎/page/{slug}‎` — والاسمُ المختصر يُكتب مرّةً حين تُنشأ ولا يتبدّل بإعادة
 * التسمية، فرابطٌ وُزّع أمسِ يفتح اليوم. وتُنشئه الشاشةُ ولا يُطلب منه.
 *
 * ═══ وأين تُحفظ ═══
 *
 * في إعدادات الموقع (`store_custom_pages`) نصًّا بصيغة JSON، داخل عقد المسوّدة
 * والنشر (`StoreContent::VERSIONED`): تُحفظ فلا يراها زبونٌ حتّى تُنشر، وتُعايَن
 * قبل الحفظ، وتُستعاد مع لقطتها. وما كُتب قبل الوحدات (صورةٌ رئيسيّة وأقسامٌ
 * وزرّ — #133) يُقرأ وحداتٍ بالترتيب نفسِه، فلا تتبدّل صفحةٌ قائمة.
 *
 * ═══ واللغة ═══
 *
 * العنوانُ اسمٌ يُضغط في القائمة: بلغة الصفحة، وإلّا باللغة الأخرى. وما سواه
 * جملٌ يكتبها لزبونه: لكلّ لغةٍ نصُّها وحدها، ولا تقع إحداهما على الأخرى —
 * ووحدةٌ لا شيءَ فيها بلغة الصفحة لا تُرسم فيها. والصورُ والمنتجاتُ للّغتين.
 *
 * ═══ ولا وسمَ يُرسم ═══
 *
 * النصوصُ تُحفظ كما كُتبت وتُطبع مُهرَّبةً (`{{ }}`)، وأسطرُها أسطرٌ
 * (`white-space: pre-line`). فلا HTML ولا `<script>` يبلغ صفحةَ زبون، والصورُ
 * من مجلّد المتجر وحده (`StoreMedia::owned`)، والمنتجاتُ من متجره وحده —
 * ويُرسم منها المفعّلُ المنشورُ وحده.
 */
final class StoreCustomPages
{
    public const KEY = 'store_custom_pages';

    /** مقطعُ الرابط تحت عنوان المتجر — `‎/page/{slug}‎` */
    public const PATH = 'page';

    /** أكثرُها — قائمةٌ رئيسيّة تُقرأ في سطر، لا فهرسُ موقع */
    public const MAX = 12;

    /** وحداتُ الصفحة — ما يُضاف إليها ويُرتَّب */
    public const BLOCKS = ['heading', 'text', 'image', 'gallery', 'textimage', 'products', 'collection', 'button', 'promo'];

    /**
     * نوعُ الصفحة ← ما يُعرض له من الوحدات.
     *
     * وصفحةُ «كتابة وصور» تقبل الصورةَ وحدها كذلك: صورةٌ تحت العنوان بعرض
     * الصفحة — وهي صفحاتُ #133 كما كُتبت.
     */
    public const KINDS = [
        'text' => ['heading', 'text', 'button'],
        'images' => ['image', 'gallery', 'button'],
        'textimage' => ['image', 'textimage', 'button'],
        'products' => ['heading', 'text', 'products', 'collection', 'button'],
        'mixed' => self::BLOCKS,
    ];

    /** نوعُ الصفحة المكتوبة قبل الأنواع — صورةٌ وأقسامٌ من نصٍّ وصورةٍ وزرّ */
    public const LEGACY_KIND = 'textimage';

    /** موضعُ الصورة من نصّها — بجانبه، أو فوقه */
    public const LAYOUTS = ['side', 'top'];

    /** أكثرُ وحدات الصفحة الواحدة */
    public const MAX_BLOCKS = 20;

    /** أكثرُ صور المعرض الواحد */
    public const MAX_GALLERY = 12;

    /** أكثرُ منتجات الوحدة الواحدة */
    public const MAX_PRODUCTS = 40;

    /** وما يُعرض من مجموعةٍ في صفحة — ورابطٌ إلى رفّها لِما بقي */
    public const COLLECTION_SHOWN = 12;

    public const TITLE_MAX = 80;

    public const INTRO_MAX = 800;

    public const HEADING_MAX = 120;

    public const SECTION_TITLE_MAX = 80;

    public const BODY_MAX = 2000;

    public const CTA_MAX = 30;

    /** أطولُ ما يُقبل من النصّ المحفوظ — حمايةٌ قبل أن يُفكّ */
    public const RAW_MAX = 300000;

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
     * معرّفاتُ المنتجات التي اختارها في صفحاته — ليعرضها المحرّرُ بأسمائها
     * ولو أُخفي أحدُها بعد اختياره.
     *
     * @param  list<array<string, mixed>>  $pages
     * @return list<int>
     */
    public static function productIds(array $pages): array
    {
        $ids = [];

        foreach ($pages as $p) {
            foreach ($p['blocks'] as $b) {
                if ($b['type'] === 'products') {
                    array_push($ids, ...$b['products']);
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * الصفحةُ كما تُرسم بلغتها — عنوانُها ونبذتُها ووحداتُها.
     *
     * ووحدةٌ لا شيءَ فيها بهذه اللغة لا تُرسم: إطارٌ فارغٌ بين قسمين أسوأُ من
     * غيابه. والزرُّ يُرسم إن كُتب نصُّه بلغة الصفحة وكانت وجهتُه تُفتح الآن.
     * والمنتجاتُ معرّفاتٌ هنا — يحوّلها المتحكّمُ بطاقاتٍ من المعروض وحده
     * (`RibbonController::customPageData`) ويُسقط ما لم يبقَ فيه شيء.
     *
     * @return array{slug: string, kind: string, title: string, intro: string, blocks: list<array<string, mixed>>, visible: bool, image: array<string, mixed>|null}
     */
    public static function view(int $businessId, array $p, string $lang, string $base): array
    {
        $sfx = $lang === 'en' ? '_en' : '_ar';

        /*
         * والوجهاتُ كلُّها تُسأل دفعةً واحدة — أزرارُ الصفحة وعروضُها — لا
         * سؤالًا لكلّ زرّ.
         */
        $targets = [];

        foreach ($p['blocks'] as $i => $b) {
            if (in_array($b['type'], ['button', 'promo'], true) && $b['target'] !== null && $b['label'.$sfx] !== '') {
                $targets[$i] = $b['target'];
            }
        }

        $hrefs = $targets === [] ? [] : array_combine(array_keys($targets), StoreTarget::hrefs($businessId, $base, $lang, array_values($targets)));
        $collections = [];

        foreach (StoreCollections::visible($businessId) as $c) {
            $collections[$c['slug']] = $c;
        }

        $out = [];
        $side = 0;
        $first = null;

        foreach ($p['blocks'] as $i => $b) {
            $view = match ($b['type']) {
                'heading' => $b['text'.$sfx] === '' ? null : ['text' => $b['text'.$sfx]],
                'text' => $b['body'.$sfx] === '' ? null : ['body' => $b['body'.$sfx]],
                'image' => ($img = self::image($businessId, $b['image'], $b['fit'])) === null ? null : ['image' => $img],
                'gallery' => ($imgs = array_values(array_filter(array_map(
                    fn (string $url) => self::image($businessId, $url, $b['fit']),
                    $b['images'],
                )))) === [] ? null : ['images' => $imgs, 'fit' => StoreMedia::fit($b['fit'])],
                'textimage' => self::textImage($businessId, $b, $sfx),
                'products' => $b['products'] === [] ? null : ['title' => $b['title'.$sfx], 'ids' => $b['products']],
                'collection' => isset($collections[$b['slug']]) ? [
                    'title' => $b['title'.$sfx] !== '' ? $b['title'.$sfx] : StoreCollections::title($collections[$b['slug']], $lang),
                    'ids' => array_values($collections[$b['slug']]['products']),
                    'href' => $base.'/shop?'.StoreCollections::QUERY.'='.$b['slug'],
                ] : null,
                'button' => ($hrefs[$i] ?? null) === null ? null : self::link($b['label'.$sfx], $hrefs[$i]),
                'promo' => self::promo($businessId, $b, $sfx, $hrefs[$i] ?? null),
                default => null,
            };

            if ($view === null) {
                continue;
            }

            // والقسمُ بجانب صورته يتناوب مع أخيه — صورةٌ يمينًا ثمّ يسارًا
            if ($b['type'] === 'textimage' && $view['image'] !== null && $view['layout'] === 'side') {
                $view['flip'] = $side % 2 === 1;
                $side++;
            }

            $first ??= $view['image'] ?? ($view['images'][0] ?? null);
            $out[] = ['type' => $b['type']] + $view;
        }

        return [
            'slug' => $p['slug'],
            'kind' => $p['kind'],
            'title' => self::title($p, $lang),
            'intro' => $p['intro'.$sfx],
            'blocks' => $out,
            'visible' => $p['visible'],
            // وأوّلُ صورةٍ فيها صورةُ مشاركتها
            'image' => $first,
        ];
    }

    /** @return array{title: string, body: string, image: array<string, mixed>|null, layout: string, flip: bool}|null */
    private static function textImage(int $businessId, array $b, string $sfx): ?array
    {
        $image = self::image($businessId, $b['image'], $b['fit']);

        if ($image === null && $b['title'.$sfx] === '' && $b['body'.$sfx] === '') {
            return null;
        }

        return ['title' => $b['title'.$sfx], 'body' => $b['body'.$sfx], 'image' => $image, 'layout' => $b['layout'], 'flip' => false];
    }

    /** @return array<string, mixed>|null */
    private static function promo(int $businessId, array $b, string $sfx, ?string $href): ?array
    {
        $image = self::image($businessId, $b['image'], $b['fit']);
        $button = $href === null ? null : self::link($b['label'.$sfx], $href);

        if ($image === null && $b['title'.$sfx] === '' && $b['body'.$sfx] === '' && $button === null) {
            return null;
        }

        return ['title' => $b['title'.$sfx], 'body' => $b['body'.$sfx], 'image' => $image, 'button' => $button];
    }

    /** @return array{label: string, href: string, external: bool} */
    private static function link(string $label, string $href): array
    {
        return ['label' => $label, 'href' => $href, 'external' => StoreTarget::external($href)];
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
     * والرسالةُ تسمّي الصفحةَ بعنوانها والوحدةَ برقمها فيها — «في صفحة
     * المناسبات، الوحدة ٣: …» — لا «الصفحة ٢» يعدّها بأصابعه.
     *
     * @param  list<array<string, mixed>>  $collections  المجموعاتُ التي يُقاس عليها ما يُختار منها —
     *                                                   ما يُحفظ معها في الطلب نفسِه، وإلّا المسوّدة
     */
    public static function validated(int $businessId, mixed $raw, array $collections): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }

        if (! is_string($raw) || strlen($raw) > self::RAW_MAX) {
            self::refuse(__('الصفحات غير صالحة — أعد فتح المحرّر واحفظ من جديد.'));
        }

        $list = json_decode($raw, true);

        if (! is_array($list) || ! array_is_list($list)) {
            self::refuse(__('الصفحات غير صالحة — أعد فتح المحرّر واحفظ من جديد.'));
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

            if (preg_match(StoreCollections::SLUG, $slug) !== 1 || in_array($slug, $slugs, true)) {
                self::refuse(__('رابط الصفحة :n غير صالح — أعد فتح المحرّر واحفظ من جديد.', ['n' => $i + 1]));
            }

            $slugs[] = $slug;
        }

        $collectionSlugs = array_map(fn (array $c) => $c['slug'], $collections);
        $out = [];
        $products = [];

        foreach ($list as $i => $p) {
            $row = ['slug' => $slugs[$i]];

            foreach (['title' => self::TITLE_MAX, 'intro' => self::INTRO_MAX] as $field => $max) {
                foreach (['_ar', '_en'] as $sfx) {
                    $row[$field.$sfx] = self::text($p[$field.$sfx] ?? '');

                    if (mb_strlen($row[$field.$sfx]) > $max) {
                        self::refuse(__('نصّ الصفحة :n أطول من :max حرفًا.', ['n' => $i + 1, 'max' => $max]));
                    }
                }
            }

            if ($row['title_ar'] === '' && $row['title_en'] === '') {
                self::refuse(__('اكتب اسمًا للصفحة :n — بالعربية أو بالإنجليزية.', ['n' => $i + 1]));
            }

            $name = $row['title_ar'] !== '' ? $row['title_ar'] : $row['title_en'];
            $legacy = ! array_key_exists('blocks', $p);
            $kind = $legacy ? self::LEGACY_KIND : (is_string($p['kind'] ?? null) ? $p['kind'] : '');

            if (! isset(self::KINDS[$kind])) {
                self::refuse(__('نوع صفحة «:page» غير معروف — اختره من جديد.', ['page' => $name]));
            }

            $blocks = $legacy ? self::legacyBlocks($p) : ($p['blocks'] ?? null);

            if (! is_array($blocks) || ! array_is_list($blocks)) {
                self::refuse(__('محتوى صفحة «:page» غير صالح — أعد فتح المحرّر واحفظ من جديد.', ['page' => $name]));
            }

            if (count($blocks) > self::MAX_BLOCKS) {
                self::refuse(__('أضف حتى :max وحدة في صفحة «:page».', ['max' => self::MAX_BLOCKS, 'page' => $name]));
            }

            $row = ['slug' => $row['slug'], 'kind' => $kind] + $row + ['blocks' => []];

            foreach ($blocks as $j => $b) {
                $at = fn (string $why) => __('في صفحة «:page»، الوحدة :m: :why', ['page' => $name, 'm' => $j + 1, 'why' => $why]);
                $type = is_array($b) && is_string($b['type'] ?? null) ? $b['type'] : '';

                if (! in_array($type, self::BLOCKS, true)) {
                    self::refuse($at(__('نوعها غير معروف.')));
                }

                if (! in_array($type, self::KINDS[$kind], true)) {
                    self::refuse($at(__('لا تناسب نوع الصفحة — غيّر نوعها أو احذف الوحدة.')));
                }

                $block = self::checkedBlock($businessId, $type, $b, $at, $collectionSlugs, $slugs);

                if ($type === 'products') {
                    array_push($products, ...$block['products']);
                }

                $row['blocks'][] = $block;
            }

            $row['visible'] = filter_var($p['visible'] ?? true, FILTER_VALIDATE_BOOL);
            $out[] = $row;
        }

        /*
         * وكلُّ منتجٍ من متجره — في سؤالٍ واحدٍ للصفحات كلّها.
         *
         * ولا يُشترط أن يكون معروضًا الآن: منتجٌ أُخفي بعد اختياره يبقى في
         * صفحته ولا يُرسم حتّى يعود (`view`). ولو رُدّ الحفظُ من أجله لَما
         * استطاع صاحبُه حفظَ شيءٍ حتّى يجده ويُزيله.
         */
        $products = array_values(array_unique($products));

        if ($products !== [] && Product::where('business_id', $businessId)->whereIn('id', $products)->count() !== count($products)) {
            self::refuse(__('منتجٌ في الصفحات ليس من منتجاتك — اختره من جديد.'));
        }

        return $out === [] ? '' : json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * وحدةٌ واحدة كما تُحفظ — حقولُ نوعها وحدها.
     *
     * @param  callable(string): string  $at  الرسالةُ بموضعها
     * @param  list<string>  $collections
     * @param  list<string>  $pages
     * @return array<string, mixed>
     */
    private static function checkedBlock(int $businessId, string $type, array $b, callable $at, array $collections, array $pages): array
    {
        $texts = fn (array $fields) => self::texts($b, $fields, $at);
        $empty = fn (array $v, array $keys) => implode('', array_map(fn ($k) => $v[$k], $keys)) === '';

        switch ($type) {
            case 'heading':
                $v = $texts(['text' => self::HEADING_MAX]);

                if ($empty($v, ['text_ar', 'text_en'])) {
                    self::refuse($at(__('اكتب العنوان أو احذف الوحدة.')));
                }

                return ['type' => $type] + $v;

            case 'text':
                $v = $texts(['body' => self::BODY_MAX]);

                if ($empty($v, ['body_ar', 'body_en'])) {
                    self::refuse($at(__('اكتب النصّ أو احذف الوحدة.')));
                }

                return ['type' => $type] + $v;

            case 'image':
                [$image, $fit] = self::checkedImage($businessId, $b['image'] ?? '', $b['fit'] ?? '', $at);

                if ($image === '') {
                    self::refuse($at(__('ارفع الصورة أو احذف الوحدة.')));
                }

                return ['type' => $type, 'image' => $image, 'fit' => $fit];

            case 'gallery':
                $images = $b['images'] ?? [];

                if (! is_array($images) || ! array_is_list($images) || $images === []) {
                    self::refuse($at(__('أضف صورةً واحدةً على الأقلّ أو احذف المعرض.')));
                }

                if (count($images) > self::MAX_GALLERY) {
                    self::refuse($at(__('أضف حتى :max صورة في المعرض.', ['max' => self::MAX_GALLERY])));
                }

                [, $fit] = self::checkedImage($businessId, '', $b['fit'] ?? '', $at);
                $urls = [];

                foreach ($images as $url) {
                    [$checked] = self::checkedImage($businessId, $url, '', $at);

                    if ($checked !== '') {
                        $urls[] = $checked;
                    }
                }

                if ($urls === []) {
                    self::refuse($at(__('أضف صورةً واحدةً على الأقلّ أو احذف المعرض.')));
                }

                return ['type' => $type, 'images' => $urls, 'fit' => $fit];

            case 'textimage':
                $v = $texts(['title' => self::SECTION_TITLE_MAX, 'body' => self::BODY_MAX]);
                [$image, $fit] = self::checkedImage($businessId, $b['image'] ?? '', $b['fit'] ?? '', $at);
                $layout = in_array($b['layout'] ?? 'side', self::LAYOUTS, true) ? ($b['layout'] ?? 'side') : null;

                if ($layout === null) {
                    self::refuse($at(__('مكان الصورة غير صالح — اختر «بجانب النص» أو «فوق النص».')));
                }

                if ($image === '' && $empty($v, ['title_ar', 'title_en', 'body_ar', 'body_en'])) {
                    self::refuse($at(__('القسم فارغ — أضف صورةً أو نصًّا، أو احذفه.')));
                }

                return ['type' => $type, 'image' => $image, 'fit' => $fit, 'layout' => $layout] + $v;

            case 'products':
                $v = $texts(['title' => self::SECTION_TITLE_MAX]);
                $ids = self::ids($b['products'] ?? null, $at);

                if ($ids === []) {
                    self::refuse($at(__('اختر منتجًا واحدًا على الأقلّ أو احذف الوحدة.')));
                }

                if (count($ids) > self::MAX_PRODUCTS) {
                    self::refuse($at(__('اختر حتى :max منتجًا في الوحدة.', ['max' => self::MAX_PRODUCTS])));
                }

                return ['type' => $type] + $v + ['products' => $ids];

            case 'collection':
                $v = $texts(['title' => self::SECTION_TITLE_MAX]);
                $slug = self::text($b['slug'] ?? '');

                if (! in_array($slug, $collections, true)) {
                    self::refuse($at(__('اختر مجموعةً من مجموعاتك.')));
                }

                return ['type' => $type, 'slug' => $slug] + $v;

            case 'button':
                $v = $texts(['label' => self::CTA_MAX]);

                if ($empty($v, ['label_ar', 'label_en'])) {
                    self::refuse($at(__('اكتب نصّ الزرّ أو احذفه.')));
                }

                return ['type' => $type] + $v + ['target' => self::checkedTarget($businessId, $b['target'] ?? null, $at, $collections, $pages)];

            default: // promo
                $v = $texts(['title' => self::SECTION_TITLE_MAX, 'body' => self::BODY_MAX, 'label' => self::CTA_MAX]);
                [$image, $fit] = self::checkedImage($businessId, $b['image'] ?? '', $b['fit'] ?? '', $at);
                // وزرُّ العرض اختياريّ — بلا نصٍّ لا زرّ، ولا وجهةَ تُطلب
                $target = $empty($v, ['label_ar', 'label_en']) ? null : self::checkedTarget($businessId, $b['target'] ?? null, $at, $collections, $pages);

                if ($image === '' && $empty($v, ['title_ar', 'title_en', 'body_ar', 'body_en'])) {
                    self::refuse($at(__('العرض فارغ — أضف عنوانًا أو نصًّا أو صورة.')));
                }

                return ['type' => $type, 'image' => $image, 'fit' => $fit] + $v + ['target' => $target];
        }
    }

    /**
     * نصوصُ الوحدة بلغتيها — وكلٌّ بحدّه.
     *
     * @param  array<string, int>  $fields  اسمُ الحقل ← أطولُه
     * @return array<string, string>
     */
    private static function texts(array $b, array $fields, callable $at): array
    {
        $out = [];

        foreach ($fields as $field => $max) {
            foreach (['_ar', '_en'] as $sfx) {
                $out[$field.$sfx] = self::text($b[$field.$sfx] ?? '');

                if (mb_strlen($out[$field.$sfx]) > $max) {
                    self::refuse($at(__('النصّ أطول من :max حرفًا.', ['max' => $max])));
                }
            }
        }

        return $out;
    }

    /**
     * صورةٌ وطريقةُ عرضها — من مجلّد المتجر وحده.
     *
     * @return array{0: string, 1: string}
     */
    private static function checkedImage(int $businessId, mixed $image, mixed $fit, callable $at): array
    {
        $image = self::text($image);
        $fit = self::text($fit);

        if (! StoreMedia::owned($businessId, $image)) {
            self::refuse($at(__('الصورة ليست من صور متجرك — ارفعها من المحرّر.')));
        }

        if ($fit !== '' && ! in_array($fit, StoreMedia::FITS, true)) {
            self::refuse($at(__('طريقة عرض الصورة غير صالحة — اختر «كاملة» أو «ملء الإطار».')));
        }

        return [$image, $fit];
    }

    /**
     * معرّفاتُ منتجاتٍ موجبةٌ فريدةٌ بترتيبها.
     *
     * @return list<int>
     */
    private static function ids(mixed $raw, callable $at): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            self::refuse($at(__('المنتجات المختارة غير صالحة — اخترها من جديد.')));
        }

        $out = [];

        foreach ($raw as $id) {
            if (! is_int($id) && ! (is_string($id) && preg_match('/^[1-9]\d{0,18}$/', $id))) {
                self::refuse($at(__('المنتجات المختارة غير صالحة — اخترها من جديد.')));
            }

            if ((int) $id > 0 && ! in_array((int) $id, $out, true)) {
                $out[] = (int) $id;
            }
        }

        return $out;
    }

    /**
     * وجهةُ زرٍّ — في متجره، أو رابطٌ مكتوبٌ آمن (`StoreTarget::LINKS`).
     *
     * @param  list<string>  $collections
     * @param  list<string>  $pages
     * @return array{type: string, value: string|int}
     */
    private static function checkedTarget(int $businessId, mixed $target, callable $at, array $collections, array $pages): array
    {
        [$checked, $why] = StoreTarget::checked($businessId, $target, $collections, $pages, StoreTarget::LINKS);

        if ($why === 'url') {
            self::refuse($at(__('الرابط غير صالح — اكتب رابطًا يبدأ بـ https:// أو بـ /.')));
        }

        if ($checked === null) {
            self::refuse($at(__('ما يفتحه الزرّ ليس في متجرك — اختر وجهته من جديد.')));
        }

        return $checked;
    }

    /**
     * ما كُتب قبل الوحدات ← وحدات: الصورةُ الرئيسيّة، ثمّ الأقسامُ، ثمّ الزرّ.
     *
     * @return list<array<string, mixed>>
     */
    private static function legacyBlocks(array $p): array
    {
        $blocks = [];

        if (self::text($p['image'] ?? '') !== '') {
            $blocks[] = ['type' => 'image', 'image' => $p['image'], 'fit' => $p['fit'] ?? ''];
        }

        foreach (is_array($p['sections'] ?? null) ? array_values($p['sections']) : [] as $s) {
            $blocks[] = is_array($s) ? ['type' => 'textimage', 'layout' => 'side'] + $s : $s;
        }

        $cta = is_array($p['cta'] ?? null) ? $p['cta'] : null;

        if ($cta !== null && (self::text($cta['label_ar'] ?? '') !== '' || self::text($cta['label_en'] ?? '') !== '')) {
            $blocks[] = ['type' => 'button'] + $cta;
        }

        return $blocks;
    }

    /* ═══════════ أدوات ═══════════ */

    /**
     * نصٌّ محفوظ ← قائمة — للقراءة، ولمن يقيس عليها ما يُحفظ معها.
     *
     * والقراءةُ متسامحة كأخواتها: وحدةٌ لا تُقرأ تسقط وحدها ولا تُسقط الصفحة،
     * وما كُتب قبل الوحدات يُقرأ وحداتٍ (`legacyBlocks`).
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

            $legacy = ! array_key_exists('blocks', $p);
            $kind = $legacy ? self::LEGACY_KIND : (is_string($p['kind'] ?? null) && isset(self::KINDS[$p['kind']]) ? $p['kind'] : 'mixed');
            $seen[$slug] = true;
            $row = ['slug' => $slug, 'kind' => $kind] + $row + ['blocks' => []];

            foreach (array_slice(is_array($blocks = $legacy ? self::legacyBlocks($p) : $p['blocks']) ? array_values($blocks) : [], 0, self::MAX_BLOCKS) as $b) {
                $block = is_array($b) ? self::readBlock($b) : null;

                if ($block !== null) {
                    $row['blocks'][] = $block;
                }
            }

            $row['visible'] = (bool) ($p['visible'] ?? true);
            $out[] = $row;
        }

        return $out;
    }

    /** وحدةٌ محفوظة ← وحدةٌ بحقول نوعها — أو `null` لما لا يُقرأ */
    private static function readBlock(array $b): ?array
    {
        $type = is_string($b['type'] ?? null) ? $b['type'] : '';
        $texts = function (array $fields) use ($b) {
            $out = [];

            foreach ($fields as $field) {
                foreach (['_ar', '_en'] as $sfx) {
                    $out[$field.$sfx] = self::text($b[$field.$sfx] ?? '');
                }
            }

            return $out;
        };
        $fit = in_array($b['fit'] ?? null, StoreMedia::FITS, true) ? $b['fit'] : '';
        $ids = fn () => array_values(array_unique(array_filter(
            array_map(fn ($v) => is_numeric($v) ? (int) $v : 0, is_array($b['products'] ?? null) ? $b['products'] : []),
            fn (int $v) => $v > 0,
        )));

        return match ($type) {
            'heading' => ['type' => $type] + $texts(['text']),
            'text' => ['type' => $type] + $texts(['body']),
            'image' => ['type' => $type, 'image' => self::text($b['image'] ?? ''), 'fit' => $fit],
            'gallery' => ['type' => $type, 'images' => array_slice(array_values(array_filter(
                array_map(fn ($u) => self::text($u), is_array($b['images'] ?? null) ? $b['images'] : []),
                fn (string $u) => $u !== '',
            )), 0, self::MAX_GALLERY), 'fit' => $fit],
            'textimage' => ['type' => $type, 'image' => self::text($b['image'] ?? ''), 'fit' => $fit,
                'layout' => in_array($b['layout'] ?? null, self::LAYOUTS, true) ? $b['layout'] : 'side'] + $texts(['title', 'body']),
            'products' => ['type' => $type] + $texts(['title']) + ['products' => array_slice($ids(), 0, self::MAX_PRODUCTS)],
            'collection' => ['type' => $type, 'slug' => self::text($b['slug'] ?? '')] + $texts(['title']),
            'button' => ($t = StoreTarget::parse($b['target'] ?? null)) === null ? null : ['type' => $type] + $texts(['label']) + ['target' => $t],
            'promo' => ['type' => $type, 'image' => self::text($b['image'] ?? ''), 'fit' => $fit] + $texts(['title', 'body', 'label'])
                + ['target' => StoreTarget::parse($b['target'] ?? null)],
            default => null,
        };
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
