<?php

namespace App\Support\Store;

use App\Models\Category;
use App\Models\Product;
use App\Support\MarketingSettings;
use Illuminate\Validation\ValidationException;

/**
 * شريطُ المجموعات المصوّر في متجر RIBBON — بطاقاتٌ بصورها فوق قائمة الصفحات.
 *
 * ═══ وأين يقع ═══
 *
 * فوق الترويسة اللاصقة لا داخلها: يمرّ مع الصفحة حين تُمرَّر، وتبقى الترويسةُ
 * (الإعلانُ والشعارُ والبحثُ والسلّةُ والقائمة) لاصقةً كما كانت بلا إزاحةٍ
 * محسوبة. ولو دخلها لَصار ارتفاعُها على الهاتف نصفَ الشاشة — بطاقاتٌ بصورها
 * لاصقةٌ فوق كلّ ما يُقرأ. وقائمةُ الصفحات (الرئيسية | المتجر | من نحن |
 * تواصل معنا) تبقى كما هي: الشريطُ يُضاف فوقها ولا يحلّ محلّها.
 *
 * ═══ ومطفأٌ حتّى يُشغَّل ═══
 *
 * لا بطاقةَ تُرسم لمتجرٍ لم يكتب شريطَه ويشغّله وينشره — والصفحةُ بلا شريطٍ
 * كما كانت حرفًا بحرف: لا عنصرَ ولا قاعدةَ CSS. والمفتاحُ في عقد النشر
 * (`StoreContent::VERSIONED`)، فما يُحفظ ينتظر النشرَ كأخواته.
 *
 * ═══ والوجهةُ داخليّةٌ وحدها ═══
 *
 * لا حقلَ رابطٍ حرّ: البطاقةُ تفتح مجموعةً أو فئةً أو صنفًا أو صفحةً من صفحات
 * المتجر، باسمها في هذا المتجر. فلا رابطَ خارجيٌّ يُوزَّع من أعلى صفحةِ
 * تاجر، ولا `javascript:`، ولا معرّفَ لمتجرٍ آخر. ووجهةٌ زالت بعد الحفظ (مجموعةٌ
 * حُذفت أو أُخفيت، صنفٌ أُوقف) تُسقط بطاقتَها عند القراءة — لا بطاقةَ تقود
 * إلى «غير موجود».
 *
 * ═══ واللغة ═══
 *
 * لكلّ لغةٍ نصُّها، ولا تقع إحداهما على الأخرى — قاعدةُ الإعلان والقسم الحرّ:
 * بطاقةٌ بلا اسمٍ بلغة الصفحة لا تُرسم فيها. ولا ترجمةَ ولا معجم.
 */
final class StoreVisualBar
{
    public const KEY = 'store_visual_bar';

    /** أكثرُ البطاقات — صفٌّ يُمرَّر بالإصبع لا معرضٌ */
    public const MAX_CARDS = 12;

    public const TITLE_MAX = 40;

    public const SUB_MAX = 80;

    public const CTA_MAX = 24;

    /** أين يظهر: الرئيسيّةُ وحدها، أو كلُّ صفحات التصفّح */
    public const ON_HOME = 'home';

    public const ON_BROWSE = 'browse';

    public const PAGES = [self::ON_HOME, self::ON_BROWSE];

    /**
     * صفحاتُ التصفّح — ولا شريطَ في السلّة والإتمام والتأكيد: هناك يدفع الزبون،
     * وبطاقاتٌ تدعوه إلى رفٍّ آخر تُخرجه من طلبه.
     */
    public const BROWSING = [StoreNav::HOME, StoreNav::SHOP, 'p', StoreNav::ABOUT, StoreNav::CONTACT];

    /** ما تفتحه البطاقة — أنواعٌ مغلقة، والقيمةُ اسمٌ أو معرّفٌ في هذا المتجر */
    public const TARGETS = ['collection', 'category', 'product', 'shop', 'best', 'about', 'contact'];

    public const RAW_MAX = 30000;

    /* ═══════════ القراءة ═══════════ */

    /**
     * الشريطُ كما حُفظ — وما لا يُقرأ يسقط بصمت.
     *
     * @return array{on: bool, pages: string, cards: list<array<string, mixed>>}
     */
    public static function settings(int $businessId): array
    {
        return self::parse((string) (MarketingSettings::group($businessId, 'website')[self::KEY] ?? ''));
    }

    /**
     * بطاقاتُ هذه الصفحة بلغتها — جاهزةً للرسم، أو قائمةٌ فارغة فلا شريط.
     *
     * @param  string  $page  مفتاحُ الصفحة كما يمرّره `RibbonController::context`
     * @return list<array{href: string, image: ?string, title: string, sub: string, cta: string}>
     */
    public static function cards(int $businessId, string $lang, string $base, string $page): array
    {
        $bar = self::settings($businessId);

        if (! $bar['on'] || $bar['cards'] === []) {
            return [];
        }

        $where = $bar['pages'] === self::ON_BROWSE ? self::BROWSING : [StoreNav::HOME];

        if (! in_array($page, $where, true)) {
            return [];
        }

        $sfx = $lang === 'en' ? '_en' : '_ar';
        $cards = array_values(array_filter(
            $bar['cards'],
            fn (array $c) => $c['visible'] && $c['title'.$sfx] !== '',
        ));

        if ($cards === []) {
            return [];
        }

        // والوجهاتُ تُسأل دفعةً واحدة — لا سؤالَ لكلّ بطاقة
        $ids = fn (string $type) => array_values(array_unique(array_map(
            fn (array $c) => (int) $c['target']['value'],
            array_filter($cards, fn (array $c) => $c['target']['type'] === $type),
        )));

        $shownProducts = Product::where('business_id', $businessId)->where('active', true)->where('published', true);
        $products = $ids('product') === [] ? [] : (clone $shownProducts)->whereIn('id', $ids('product'))->pluck('id')->map(fn ($i) => (int) $i)->all();
        // والفئةُ تُفتح إن كان فيها صنفٌ معروض — لا بطاقةَ إلى رفٍّ خالٍ
        $categories = $ids('category') === [] ? [] : Category::where('business_id', $businessId)
            ->whereIn('id', $ids('category'))
            ->whereIn('id', (clone $shownProducts)->select('category_id'))
            ->pluck('id')->map(fn ($i) => (int) $i)->all();

        $out = [];

        foreach ($cards as $c) {
            $href = self::href($businessId, $c['target'], $base, $lang, $products, $categories);

            if ($href === null) {
                continue;
            }

            $image = $c['image'];

            // وبلا صورةٍ لها تُستعار صورةُ المجموعة التي تفتحها — إن كان لها
            if ($image === '' && $c['target']['type'] === 'collection') {
                $image = (string) (StoreCollections::find($businessId, (string) $c['target']['value'])['image'] ?? '');
            }

            $out[] = [
                'href' => $href,
                'image' => $image !== '' ? $image : null,
                'title' => $c['title'.$sfx],
                'sub' => $c['sub'.$sfx],
                'cta' => $c['cta'.$sfx],
            ];
        }

        return $out;
    }

    /**
     * رابطُ الوجهة — أو `null` فلا تُرسم البطاقة.
     *
     * @param  list<int>  $products  ما يُعرض من الأصناف المطلوبة
     * @param  list<int>  $categories  ما فيه صنفٌ معروض من الفئات المطلوبة
     */
    private static function href(int $businessId, array $target, string $base, string $lang, array $products, array $categories): ?string
    {
        $value = $target['value'];

        return match ($target['type']) {
            'collection' => StoreCollections::find($businessId, (string) $value) !== null
                ? $base.'/shop?'.StoreCollections::QUERY.'='.$value : null,
            'category' => in_array((int) $value, $categories, true) ? $base.'/shop?cat='.(int) $value : null,
            'product' => in_array((int) $value, $products, true) ? $base.'/p/'.(int) $value : null,
            'shop' => $base.'/shop',
            'best' => $base.'/shop?view='.StoreHeader::VIEW_BEST,
            'about', 'contact' => StoreNav::has($businessId, $target['type'], $lang) ? $base.'/'.$target['type'] : null,
            default => null,
        };
    }

    /* ═══════════ الحفظ ═══════════ */

    /**
     * ما أرسلته الشاشة ← نصُّ JSON يُحفظ — أو رفضٌ برسالة.
     *
     * @param  list<array<string, mixed>>  $collections  المجموعاتُ التي تُقاس عليها الوجهات —
     *                                                   ما يُحفظ معها في الطلب نفسِه، وإلّا المسوّدة
     */
    public static function validated(int $businessId, mixed $raw, array $collections): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }

        if (! is_string($raw) || strlen($raw) > self::RAW_MAX) {
            self::refuse(__('الشريط المصوّر غير صالح — أعد فتح المحرّر واحفظ من جديد.'));
        }

        $bar = json_decode($raw, true);

        if (! is_array($bar) || array_is_list($bar)) {
            self::refuse(__('الشريط المصوّر غير صالح — أعد فتح المحرّر واحفظ من جديد.'));
        }

        $pages = self::text($bar['pages'] ?? self::ON_HOME);

        if (! in_array($pages, self::PAGES, true)) {
            self::refuse(__('اختر أين يظهر الشريط: الرئيسية وحدها أو كل صفحات التصفّح.'));
        }

        $cards = $bar['cards'] ?? [];

        if (! is_array($cards) || ! array_is_list($cards)) {
            self::refuse(__('الشريط المصوّر غير صالح — أعد فتح المحرّر واحفظ من جديد.'));
        }

        if (count($cards) > self::MAX_CARDS) {
            self::refuse(__('أضف حتى :max بطاقة فقط.', ['max' => self::MAX_CARDS]));
        }

        $slugs = array_map(fn (array $c) => $c['slug'], $collections);
        $out = [];

        foreach ($cards as $i => $c) {
            $n = $i + 1;

            if (! is_array($c)) {
                self::refuse(__('الشريط المصوّر غير صالح — أعد فتح المحرّر واحفظ من جديد.'));
            }

            $card = ['image' => self::text($c['image'] ?? '')];

            foreach (['title' => self::TITLE_MAX, 'sub' => self::SUB_MAX, 'cta' => self::CTA_MAX] as $field => $max) {
                foreach (['_ar', '_en'] as $sfx) {
                    $card[$field.$sfx] = self::text($c[$field.$sfx] ?? '');

                    if (mb_strlen($card[$field.$sfx]) > $max) {
                        self::refuse(__('نصّ البطاقة :n أطول من :max حرفًا.', ['n' => $n, 'max' => $max]));
                    }
                }
            }

            if ($card['title_ar'] === '' && $card['title_en'] === '') {
                self::refuse(__('اكتب عنوانًا للبطاقة :n — بالعربية أو بالإنجليزية.', ['n' => $n]));
            }

            if (! StoreMedia::owned($businessId, $card['image'])) {
                self::refuse(__('صورة البطاقة :n ليست من صور متجرك — ارفعها من المحرّر.', ['n' => $n]));
            }

            $card['target'] = self::target($businessId, $c['target'] ?? null, $slugs, $n);
            $card['visible'] = filter_var($c['visible'] ?? true, FILTER_VALIDATE_BOOL);
            $out[] = $card;
        }

        return json_encode([
            'on' => filter_var($bar['on'] ?? false, FILTER_VALIDATE_BOOL),
            'pages' => $pages,
            'cards' => $out,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * وجهةُ البطاقة كما تُحفظ — أو رفض.
     *
     * @param  list<string>  $slugs
     * @return array{type: string, value: string|int}
     */
    private static function target(int $businessId, mixed $target, array $slugs, int $n): array
    {
        $type = is_array($target) ? self::text($target['type'] ?? '') : '';
        $value = is_array($target) ? ($target['value'] ?? '') : '';

        if (! in_array($type, self::TARGETS, true)) {
            self::refuse(__('اختر ما تفتحه البطاقة :n — مجموعةً أو فئةً أو منتجًا أو صفحةً من متجرك.', ['n' => $n]));
        }

        $id = is_int($value) || (is_string($value) && preg_match('/^[1-9]\d{0,18}$/', $value)) ? (int) $value : 0;

        $ok = match ($type) {
            'collection' => is_string($value) && in_array($value, $slugs, true),
            'category' => $id > 0 && Category::where('business_id', $businessId)->whereKey($id)->exists(),
            'product' => $id > 0 && Product::where('business_id', $businessId)->whereKey($id)
                ->where('active', true)->where('published', true)->exists(),
            default => true,
        };

        if (! $ok) {
            self::refuse(__('ما تفتحه البطاقة :n ليس في متجرك — اختر وجهتها من جديد.', ['n' => $n]));
        }

        return ['type' => $type, 'value' => match ($type) {
            'collection' => (string) $value,
            'category', 'product' => $id,
            default => '',
        }];
    }

    /* ═══════════ أدوات ═══════════ */

    /** @return array{on: bool, pages: string, cards: list<array<string, mixed>>} */
    private static function parse(string $raw): array
    {
        $off = ['on' => false, 'pages' => self::ON_HOME, 'cards' => []];

        if ($raw === '') {
            return $off;
        }

        $bar = json_decode($raw, true);

        if (! is_array($bar) || array_is_list($bar)) {
            return $off;
        }

        $cards = [];

        foreach (array_slice(is_array($bar['cards'] ?? null) ? array_values($bar['cards']) : [], 0, self::MAX_CARDS) as $c) {
            $type = is_array($c) && is_array($c['target'] ?? null) ? self::text($c['target']['type'] ?? '') : '';

            if (! in_array($type, self::TARGETS, true)) {
                continue;
            }

            $card = [
                'image' => self::text($c['image'] ?? ''),
                'target' => ['type' => $type, 'value' => is_scalar($c['target']['value'] ?? null) ? $c['target']['value'] : ''],
                'visible' => (bool) ($c['visible'] ?? true),
            ];

            foreach (['title', 'sub', 'cta'] as $field) {
                foreach (['_ar', '_en'] as $sfx) {
                    $card[$field.$sfx] = self::text($c[$field.$sfx] ?? '');
                }
            }

            $cards[] = $card;
        }

        return [
            'on' => (bool) ($bar['on'] ?? false),
            'pages' => in_array($bar['pages'] ?? null, self::PAGES, true) ? $bar['pages'] : self::ON_HOME,
            'cards' => $cards,
        ];
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function refuse(string $message): never
    {
        throw ValidationException::withMessages([self::KEY => $message]);
    }
}
