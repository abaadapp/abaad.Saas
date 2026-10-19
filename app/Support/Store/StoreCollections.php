<?php

namespace App\Support\Store;

use App\Models\Product;
use App\Support\MarketingSettings;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * مجموعاتُ متجر RIBBON — رفوفٌ يختار صاحبُها أصنافَها بيده، غيرُ الفئات.
 *
 * ═══ ولمَ ليست فئات ═══
 *
 * الفئةُ تصنيفٌ: كلُّ صنفٍ في فئةٍ واحدة، وبها يُرتَّب الصندوقُ والتقارير
 * و«تسوّق حسب الفئة». و«هدايا التخرّج» و«عروض خاصّة» ليست تصنيفًا: باقةٌ
 * وعلبةُ شوكولاتة وبالون من ثلاث فئاتٍ في رفٍّ واحد، والباقةُ نفسُها في
 * «هدايا التخرّج» و«أعياد الميلاد» معًا. ولو صُنعت فئاتٍ لَنُقل الصنفُ من
 * فئته ليظهر في رفّ — فيفسد تصنيفُه في كلّ شاشة. وهي قاعدةُ «اختيارات
 * RIBBON» نفسُها (`RibbonPicks`): الاختيارُ عرضٌ لا تصنيف.
 *
 * ═══ وأين تُحفظ ═══
 *
 * في إعدادات الموقع (`store_collections`) نصًّا بصيغة JSON، لا في جدول. فهي
 * ممّا يراه الزائر وحده، فتدخل عقدَ المسوّدة والنشر (`StoreContent::VERSIONED`)
 * كأخواتها: يحفظها صاحبُها فلا يراها زبونُه حتّى ينشر، وتُعايَن قبل الحفظ،
 * وتُستعاد مع لقطتها. وجدولٌ لها كان سيُكتب حيًّا لحظةَ الحفظ — خارجَ النشر.
 *
 * والأصنافُ معرّفاتٌ بترتيبها كما في `ProductList`: الحفظُ صارمٌ (أصنافُ
 * متجره المعروضةُ وحدها)، والقراءةُ متسامحة (ما يُعرض الآن، بترتيبه — وصنفٌ
 * أُخفي بعد اختياره يسقط وحده). فلا يُخزَّن اسمٌ ولا سعرٌ يفترق عن الصنف.
 *
 * ═══ ورابطُها ═══
 *
 * `‎/shop?collection={slug}‎` — والاسمُ المختصر يُكتب مرّةً حين تُنشأ ولا يتبدّل
 * بإعادة التسمية، فرابطٌ وُزّع أمسِ يفتح اليوم. ويُقرأ من إعداد هذا المتجر
 * وحده: لا معرّفَ يُطلب من القاعدة، فلا تُفتح مجموعةُ متجرٍ آخر بأيّ رابط.
 * ومجموعةٌ أخفاها صاحبُها أو اسمٌ لا يُعرف يُردّ «غير موجود» — كصفحةٍ أُطفئت.
 *
 * ═══ ولا يُنشأ شيءٌ من تلقاء نفسه ═══
 *
 * فارغةٌ لكلّ متجرٍ حتّى يكتبها صاحبُه: لا مجموعاتٌ مقترَحة، ولا شيءَ يتبدّل
 * في متجرٍ قائمٍ يومَ النشر.
 */
final class StoreCollections
{
    /** مفتاحُها في إعدادات الموقع */
    public const KEY = 'store_collections';

    /** اسمُ المُدخَل في رابط الرفّ — `‎?collection=…‎` */
    public const QUERY = 'collection';

    /** أكثرُها — صفُّ خيارات المتجر يُمرَّر بالإصبع، لا قائمةٌ ثانية */
    public const MAX = 20;

    /** أكثرُ ما في المجموعة الواحدة — رفٌّ يُقرأ لا فهرسٌ */
    public const MAX_PRODUCTS = 60;

    public const TITLE_MAX = 60;

    public const DESC_MAX = 300;

    /** الاسمُ المختصر في الرابط — حروفٌ إنجليزيّةٌ صغيرةٌ وأرقامٌ وشرطةٌ بين حرفين */
    public const SLUG = '/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/';

    /** أطولُ ما يُقبل من النصّ المحفوظ — حمايةٌ قبل أن يُفكّ */
    public const RAW_MAX = 60000;

    /* ═══════════ القراءة ═══════════ */

    /**
     * المجموعاتُ كما حُفظت — بترتيبها، وما لا يُقرأ منها يسقط بصمت.
     *
     * والقراءةُ لا تثق بالمخزَّن: صفٌّ كُتب قبل قاعدةٍ أو بيدٍ في القاعدة
     * لا يُسقط الصفحة. يُقرأ منه ما يصحّ، ويُترك ما لا يصحّ.
     *
     * @return list<array{slug: string, title_ar: string, title_en: string, desc_ar: string, desc_en: string, image: string, fit: string, visible: bool, in_row: bool, products: list<int>}>
     */
    public static function all(int $businessId): array
    {
        return self::fromJson((string) (MarketingSettings::group($businessId, 'website')[self::KEY] ?? ''));
    }

    /**
     * ما يُرى منها — بترتيبها.
     *
     * @return list<array<string, mixed>>
     */
    public static function visible(int $businessId): array
    {
        return array_values(array_filter(self::all($businessId), fn (array $c) => $c['visible']));
    }

    /** مجموعةٌ ظاهرةٌ بالاسم المختصر — من هذا المتجر وحده — أو `null` */
    public static function find(int $businessId, string $slug): ?array
    {
        foreach (self::visible($businessId) as $c) {
            if ($c['slug'] === $slug) {
                return $c;
            }
        }

        return null;
    }

    /**
     * مجموعةٌ بالاسم المختصر ولو أخفاها صاحبُها — للمعاينة وحدها.
     *
     * فمن يجهّز مجموعةً مخفيّةً يراها في معاينته قبل أن يُظهرها، وزبونُه لا
     * يبلغها بأيّ رابط (`find`). انظر `RibbonController::shop`.
     */
    public static function findAny(int $businessId, string $slug): ?array
    {
        foreach (self::all($businessId) as $c) {
            if ($c['slug'] === $slug) {
                return $c;
            }
        }

        return null;
    }

    /**
     * اسمُها بلغة الصفحة — وإلّا اسمُها باللغة الأخرى.
     *
     * قاعدةُ أسماء الأصناف والفئات نفسُها: ما كُتب للّغة، وإلّا ما كُتب. فهو
     * اسمُ رفٍّ يُضغط في الصفّ والشريط، وزرٌّ بلا اسمٍ لا يُضغط. ولا ترجمةَ
     * ولا معجمَ — ما كتبه صاحبُه وحده.
     */
    public static function title(array $c, string $lang): string
    {
        $mine = $lang === 'en' ? $c['title_en'] : $c['title_ar'];

        return $mine !== '' ? $mine : ($lang === 'en' ? $c['title_ar'] : $c['title_en']);
    }

    /**
     * وصفُها بلغة الصفحة وحدها — وفارغُ اللغة لا يُرسم.
     *
     * والوصفُ جملٌ يكتبها لزبونه كالإعلان والقسم الحرّ: جملةٌ عربيّةٌ في
     * صفحةٍ إنجليزيّة أسوأُ من لا جملة.
     */
    public static function description(array $c, string $lang): string
    {
        return $lang === 'en' ? $c['desc_en'] : $c['desc_ar'];
    }

    /**
     * أصنافُها — من `$shown` وحدها، بترتيبه.
     *
     * @param  Collection<int, Product>  $shown  المعروضُ من هذا المتجر
     * @return Collection<int, Product>
     */
    public static function members(array $c, Collection $shown): Collection
    {
        return ProductList::pick($shown, $c['products']);
    }

    /* ═══════════ الحفظ ═══════════ */

    /**
     * ما أرسلته الشاشة ← نصُّ JSON يُحفظ — أو رفضٌ برسالة.
     *
     * والفراغُ صالح: لا مجموعات. وكلُّ ما سوى الحقول المعروفة يُطرح، فلا
     * يُخزَّن ما لا يُقرأ. والنصوصُ تُحفظ كما كُتبت وتُهرَّب حيث تُرسم.
     */
    public static function validated(int $businessId, mixed $raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }

        if (! is_string($raw) || strlen($raw) > self::RAW_MAX) {
            self::refuse(__('قائمة المجموعات غير صالحة — أعد فتح المحرّر واحفظ من جديد.'));
        }

        $list = json_decode($raw, true);

        if (! is_array($list) || ! array_is_list($list)) {
            self::refuse(__('قائمة المجموعات غير صالحة — أعد فتح المحرّر واحفظ من جديد.'));
        }

        if (count($list) > self::MAX) {
            self::refuse(__('أنشئ حتى :max مجموعة فقط.', ['max' => self::MAX]));
        }

        $out = [];
        $slugs = [];
        $ids = [];

        foreach ($list as $i => $c) {
            if (! is_array($c)) {
                self::refuse(__('قائمة المجموعات غير صالحة — أعد فتح المحرّر واحفظ من جديد.'));
            }

            $n = $i + 1;
            $slug = self::text($c['slug'] ?? '');

            if (preg_match(self::SLUG, $slug) !== 1) {
                self::refuse(__('رابط المجموعة :n غير صالح — حروفٌ إنجليزية صغيرة وأرقام وشرطة.', ['n' => $n]));
            }

            if (in_array($slug, $slugs, true)) {
                self::refuse(__('رابطان لمجموعتين بالاسم نفسه «:slug» — لكلّ مجموعةٍ رابطها.', ['slug' => $slug]));
            }

            $slugs[] = $slug;

            $row = [
                'slug' => $slug,
                'title_ar' => self::text($c['title_ar'] ?? ''),
                'title_en' => self::text($c['title_en'] ?? ''),
                'desc_ar' => self::text($c['desc_ar'] ?? ''),
                'desc_en' => self::text($c['desc_en'] ?? ''),
                'image' => self::text($c['image'] ?? ''),
                'visible' => filter_var($c['visible'] ?? true, FILTER_VALIDATE_BOOL),
                'in_row' => filter_var($c['in_row'] ?? true, FILTER_VALIDATE_BOOL),
                'products' => [],
            ];

            if ($row['title_ar'] === '' && $row['title_en'] === '') {
                self::refuse(__('اكتب اسمًا للمجموعة :n — بالعربية أو بالإنجليزية.', ['n' => $n]));
            }

            foreach (['title_ar', 'title_en'] as $k) {
                if (mb_strlen($row[$k]) > self::TITLE_MAX) {
                    self::refuse(__('اسم المجموعة :n أطول من :max حرفًا.', ['n' => $n, 'max' => self::TITLE_MAX]));
                }
            }

            foreach (['desc_ar', 'desc_en'] as $k) {
                if (mb_strlen($row[$k]) > self::DESC_MAX) {
                    self::refuse(__('وصف المجموعة :n أطول من :max حرفًا.', ['n' => $n, 'max' => self::DESC_MAX]));
                }
            }

            if (! StoreMedia::owned($businessId, $row['image'])) {
                self::refuse(__('صورة المجموعة :n ليست من صور متجرك — ارفعها من المحرّر.', ['n' => $n]));
            }

            /*
             * وطريقةُ عرض صورتها — «كاملة» أو «ملء الإطار» — تُحفظ إن اختيرت.
             * وما لم يُختر يبقى «ملء الإطار» كما كان (`StoreMedia::fit`)، فلا
             * يتبدّل الحفظُ القديمُ حرفًا.
             */
            $fit = self::text($c['fit'] ?? '');

            if ($fit !== '' && ! in_array($fit, StoreMedia::FITS, true)) {
                self::refuse(__('طريقة عرض صورة المجموعة :n غير صالحة.', ['n' => $n]));
            }

            if ($fit !== '') {
                $row = array_slice($row, 0, 6, true) + ['fit' => $fit] + array_slice($row, 6, null, true);
            }

            $products = $c['products'] ?? [];

            if (! is_array($products) || ! array_is_list($products)) {
                self::refuse(__('أصناف المجموعة :n غير صالحة — أعد اختيارها.', ['n' => $n]));
            }

            foreach ($products as $id) {
                if (! is_int($id) && ! (is_string($id) && preg_match('/^[1-9]\d{0,18}$/', $id))) {
                    self::refuse(__('أصناف المجموعة :n غير صالحة — أعد اختيارها.', ['n' => $n]));
                }

                $id = (int) $id;

                if ($id <= 0) {
                    self::refuse(__('أصناف المجموعة :n غير صالحة — أعد اختيارها.', ['n' => $n]));
                }

                if (! in_array($id, $row['products'], true)) {
                    $row['products'][] = $id;
                }
            }

            if (count($row['products']) > self::MAX_PRODUCTS) {
                self::refuse(__('اختر حتى :max منتجًا في المجموعة :n.', ['max' => self::MAX_PRODUCTS, 'n' => $n]));
            }

            $ids = array_merge($ids, $row['products']);
            $out[] = $row;
        }

        /*
         * وكلُّ صنفٍ من متجره، مفعّلٌ ومنشور — في سؤالٍ واحدٍ للقائمة كلّها.
         *
         * فلا يُحفظ معرّفُ صنفِ متجرٍ آخر ولو أرسله بيده، ولا صنفٌ لا يُعرض
         * (قاعدةُ `ProductList::validated` نفسُها). والشاشةُ لا ترسل إلّا ما
         * يُعرض الآن (`keepShown`)، فمن بلغ هذا الردَّ أرسل ما لا تعرضه.
         */
        $ids = array_values(array_unique($ids));

        if ($ids !== []) {
            $found = Product::where('business_id', $businessId)
                ->where('active', true)->where('published', true)
                ->whereIn('id', $ids)->count();

            if ($found !== count($ids)) {
                self::refuse(__('منتجٌ في المجموعات غير متاح — اختر من منتجاتك المفعّلة والمنشورة.'));
            }
        }

        return $out === [] ? '' : json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /* ═══════════ أدوات ═══════════ */

    /**
     * نصٌّ محفوظ ← قائمة — للقراءة، ولمن يقيس عليها ما يُحفظ معها (`StoreVisualBar`).
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

        foreach (array_slice($list, 0, self::MAX) as $c) {
            if (! is_array($c)) {
                continue;
            }

            $slug = self::text($c['slug'] ?? '');

            if (preg_match(self::SLUG, $slug) !== 1 || isset($seen[$slug])) {
                continue;
            }

            $seen[$slug] = true;
            $title = [self::text($c['title_ar'] ?? ''), self::text($c['title_en'] ?? '')];

            if ($title === ['', '']) {
                continue;
            }

            $out[] = [
                'slug' => $slug,
                'title_ar' => $title[0],
                'title_en' => $title[1],
                'desc_ar' => self::text($c['desc_ar'] ?? ''),
                'desc_en' => self::text($c['desc_en'] ?? ''),
                'image' => self::text($c['image'] ?? ''),
                'fit' => in_array($c['fit'] ?? null, StoreMedia::FITS, true) ? $c['fit'] : '',
                'visible' => (bool) ($c['visible'] ?? true),
                'in_row' => (bool) ($c['in_row'] ?? true),
                'products' => StorePage::ids(implode(',', array_map('strval', array_filter((array) ($c['products'] ?? []), 'is_scalar'))), self::MAX_PRODUCTS),
            ];
        }

        return $out;
    }

    /** نصٌّ من الحمولة — وما ليس نصًّا فراغ */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function refuse(string $message): never
    {
        throw ValidationException::withMessages([self::KEY => $message]);
    }
}
