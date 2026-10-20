<?php

namespace App\Support\Store;

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
    public const BROWSING = [StoreNav::HOME, StoreNav::SHOP, 'p', StoreNav::ABOUT, StoreNav::CONTACT, StoreCustomPages::PATH];

    /** ما تفتحه البطاقة — أنواعُ `StoreTarget` المغلقة، والقيمةُ اسمٌ أو معرّفٌ في هذا المتجر */
    public const TARGETS = StoreTarget::TYPES;

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
     * @return list<array{href: string, image: ?string, fit: string, title: string, sub: string, cta: string}>
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

        // والوجهاتُ تُسأل دفعةً واحدة — لا سؤالَ لكلّ بطاقة (`StoreTarget::hrefs`)
        $hrefs = StoreTarget::hrefs($businessId, $base, $lang, array_map(fn (array $c) => $c['target'], $cards));
        $out = [];

        foreach ($cards as $i => $c) {
            if ($hrefs[$i] === null) {
                continue;
            }

            $image = $c['image'];
            $fit = $c['fit'];

            // وبلا صورةٍ لها تُستعار صورةُ المجموعة التي تفتحها — بطريقة عرضها
            if ($image === '' && $c['target']['type'] === 'collection') {
                $collection = StoreCollections::find($businessId, (string) $c['target']['value']);
                $image = (string) ($collection['image'] ?? '');
                $fit = (string) ($collection['fit'] ?? '');
            }

            $out[] = [
                'href' => $hrefs[$i],
                'image' => $image !== '' ? $image : null,
                'fit' => StoreMedia::fit($fit),
                'title' => $c['title'.$sfx],
                'sub' => $c['sub'.$sfx],
                'cta' => $c['cta'.$sfx],
            ];
        }

        return $out;
    }

    /* ═══════════ الحفظ ═══════════ */

    /**
     * ما أرسلته الشاشة ← نصُّ JSON يُحفظ — أو رفضٌ برسالة.
     *
     * @param  list<array<string, mixed>>  $collections  المجموعاتُ التي تُقاس عليها الوجهات —
     *                                                   ما يُحفظ معها في الطلب نفسِه، وإلّا المسوّدة
     * @param  list<array<string, mixed>>  $customPages  والصفحاتُ المخصّصة كذلك (`StoreCustomPages`)
     */
    public static function validated(int $businessId, mixed $raw, array $collections, array $customPages = []): string
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
        $pageSlugs = array_map(fn (array $p) => $p['slug'], $customPages);
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

            [$target, $why] = StoreTarget::checked($businessId, $c['target'] ?? null, $slugs, $pageSlugs);

            if ($why === 'type') {
                self::refuse(__('اختر ما تفتحه البطاقة :n — مجموعةً أو فئةً أو منتجًا أو صفحةً من متجرك.', ['n' => $n]));
            }

            if ($target === null) {
                self::refuse(__('ما تفتحه البطاقة :n ليس في متجرك — اختر وجهتها من جديد.', ['n' => $n]));
            }

            $card['target'] = $target;
            $card['visible'] = filter_var($c['visible'] ?? true, FILTER_VALIDATE_BOOL);

            // وطريقةُ عرض الصورة تُحفظ إن اختيرت — وما لم يُختر يبقى «ملء الإطار» كما كان
            $fit = self::text($c['fit'] ?? '');

            if ($fit !== '' && ! in_array($fit, StoreMedia::FITS, true)) {
                self::refuse(__('طريقة عرض صورة البطاقة :n غير صالحة.', ['n' => $n]));
            }

            if ($fit !== '') {
                $card['fit'] = $fit;
            }

            $out[] = $card;
        }

        return json_encode([
            'on' => filter_var($bar['on'] ?? false, FILTER_VALIDATE_BOOL),
            'pages' => $pages,
            'cards' => $out,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /* ═══════════ أدوات ═══════════ */

    /**
     * أمشغَّلٌ هو في هذا النصّ؟ — يقرؤه المحرّرُ من المسوّدة ليضع صفَّه:
     * المشغَّلُ بين أبواب المحرّر الأولى، والمطفأُ تحت «إعدادات متقدّمة»
     * ببطاقاته وصوره كما حُفظت — لا يُحذف منها شيء (`PageEditor::rows`).
     */
    public static function switchedOn(string $raw): bool
    {
        return self::parse($raw)['on'];
    }

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
            $target = is_array($c) ? StoreTarget::parse($c['target'] ?? null) : null;

            // والرابطُ المكتوب ليس من أنواع البطاقة — للقائمة وزرّ الصفحة وحدهما
            if ($target === null || ! in_array($target['type'], self::TARGETS, true)) {
                continue;
            }

            $card = [
                'image' => self::text($c['image'] ?? ''),
                'fit' => in_array($c['fit'] ?? null, StoreMedia::FITS, true) ? $c['fit'] : '',
                'target' => $target,
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
