<?php

namespace App\Support\Store;

use App\Models\Product;
use App\Support\MarketingSettings;
use App\Support\Website\MerchantData;
use Illuminate\Validation\ValidationException;

/**
 * صفحاتُ متجر الواجهة الخاصّة — ما منها قائمٌ، وما يُكتب في القائمة.
 *
 * ═══ ولمَ صارت مصدرًا واحدًا ═══
 *
 * أربعةٌ يسألون السؤالَ نفسه: قائمةُ الترويسة، وعمودُ التذييل، والمتحكّم
 * حين يُطلب `‎/about‎` فيقرّر أيخدمها أم يردّ «غير موجود»، وشاشةُ
 * «الصفحات» في لوحة صاحبه. ولو أجاب كلٌّ منهم بنفسه لَظهر في القائمة
 * رابطٌ إلى صفحةٍ تردّ ٤٠٤ — وهو أوّلُ ما يقع حين يفترق الأربعة.
 *
 * ═══ وصفحةٌ بلا ما تقوله لا تُفتح ═══
 *
 * «من نحن» بلا نبذةٍ صفحةٌ بيضاء، و«تواصل معنا» بلا هاتفٍ ولا عنوانٍ ولا
 * ساعاتٍ كذلك. والزبون الذي ضغط الرابطَ فوجد فراغًا لا يعود يضغط ثالثةً.
 *
 * فالصفحةُ تُعرض إن اجتمع اثنان: أذِن صاحبُها، **وفيها ما يُقال**. وما
 * سقط منهما سقط لأنّه لا وجود له لا لأنّه مُنع.
 *
 * ═══ والفراغُ يعني «كلُّها» ═══
 *
 * قاعدةُ `StorePage::order` نفسُها: مفتاحٌ لم يُكتب يُقرأ بما كان. فترقيةٌ
 * تُنزل المفتاح فارغًا على متجرٍ قائم لا تُطفئ له صفحة.
 *
 * ═══ والقائمةُ يكتبها صاحبُها إن شاء (`store_menu`) ═══
 *
 * يرتّب الصفحاتِ الأربعَ كما يريد، ويضيف بينها روابطَ إلى صفحاتٍ كتبها
 * (`StoreCustomPages`) أو مجموعاتٍ أو فئاتٍ أو صفحاتٍ من متجره (`StoreTarget`)،
 * وعناوينَ لا تُضغط (`label`): اسمٌ يُقرأ في القائمة بلا رابطٍ ولا `href="#"`.
 * والعنوانُ غيرُ صفحةِ الكتابة: هذه رابطٌ إلى صفحة، وذاك كلمةٌ لا تفتح شيئًا.
 * والصفحاتُ الأربعُ لا تُحذف منها: تُرتَّب، وظهورُ «من نحن» و«تواصل معنا»
 * يبقى لمفتاحَيهما ولِما فيهما (`has`) — فلا يفترق بابان يُطفئان صفحةً واحدة.
 * والفراغُ القائمةُ القديمةُ بترتيبها حرفًا بحرف.
 */
final class StoreNav
{
    public const HOME = 'home';

    public const SHOP = 'shop';

    public const ABOUT = 'about';

    public const CONTACT = 'contact';

    /** الصفحاتُ الأربعُ بترتيبها في القائمة — كترتيب المتجر العامّ */
    public const ALL = [self::HOME, self::SHOP, self::ABOUT, self::CONTACT];

    /**
     * ما يُطفأ ويُشعل — والرئيسيةُ والمتجرُ ليستا منه.
     *
     * الرئيسيةُ هي العنوان نفسُه فلا مفتاحَ لها، و«المتجر» هي البضاعة —
     * ومتجرٌ يُطفئ بضاعتَه ليس متجرًا. وظهورُهما مع ذلك ليس مضمونًا:
     * رفٌّ خالٍ يُسقط «المتجر» من القائمة (انظر `has`).
     */
    public const OPTIONAL = [self::ABOUT, self::CONTACT];

    /**
     * ما يُكتب حين يُطفئ الاثنتين معًا — ورمزٌ لأنّ الفراغَ مشغول.
     *
     * الفراغُ يعني «ما كان» فيُقرأ «كلُّها»، فلو أرسلت الشاشةُ فراغًا عند
     * إطفاء الأخيرة لَانقلب الإطفاءُ إشعالًا — وهو مقبضٌ يفعل عكسَ ما عليه
     * مكتوب. والرمزُ ليس اسمَ صفحةٍ فيسقط في الترشيح كما يسقط أيُّ اسمٍ
     * غريب، فلا يحتاج استثناءً في القراءة.
     */
    public const NONE = 'none';

    /** مفتاحُ القائمة المرتّبة في إعدادات الموقع — نصُّ JSON، والفراغُ القائمةُ القديمة */
    public const MENU = 'store_menu';

    /** أكثرُ ما يُضاف إليها من روابط وعناوين — سطرٌ يُمرَّر بالإصبع، لا فهرسٌ ثانٍ */
    public const MAX_LINKS = 10;

    /** اسمُ الرابط في القائمة — كلمةٌ أو كلمتان */
    public const LINK_TITLE_MAX = 30;

    /** معرّفُ الرابط كما تكتبه الشاشة — يُبقيه ثابتًا بين حفظةٍ وأخرى */
    private const LINK_ID = '/^[a-z0-9]{1,16}$/';

    /** أطولُ ما يُقبل من النصّ المحفوظ — حمايةٌ قبل أن يُفكّ */
    public const MENU_RAW_MAX = 8000;

    /** مسارُ كلّ صفحة تحت عنوان المتجر — والرئيسيةُ جذرُه */
    public const PATHS = [
        self::HOME => '/',
        self::SHOP => '/shop',
        self::ABOUT => '/about',
        self::CONTACT => '/contact',
    ];

    /** مفتاحُ اسمها في `RibbonTexts` — فتُقرأ بلغة الزائر */
    public const LABELS = [
        self::HOME => 'navHome',
        self::SHOP => 'navShop',
        self::ABOUT => 'navAbout',
        self::CONTACT => 'navContact',
    ];

    /**
     * ما أذِن به صاحبُه من الصفحات الاختيارية.
     *
     * @return list<string>
     */
    public static function allowed(int $businessId): array
    {
        return self::allowedFrom(MarketingSettings::group($businessId, 'website')['store_pages'] ?? '');
    }

    /**
     * القاعدةُ نفسُها على نصٍّ يُعطى — لا على ما هو منشور.
     *
     * وفُصلت عن `allowed` لأنّ لوحةَ صاحب المتجر تقرأ **المسوّدة** لا
     * المنشور: من أطفأ «من نحن» وحفظ ولم يَنشر يجب أن يرى مفتاحَه مطفأً
     * حين يعود، لا مشتعلًا كما هو عند زبونه بعد.
     *
     * والمصدرُ وحدَه هو ما تبدّل — والقاعدةُ واحدةٌ في موضعٍ واحد.
     *
     * @return list<string>
     */
    public static function allowedFrom(?string $value): array
    {
        $raw = trim((string) $value);

        /*
         * والفراغُ وحدَه يعني «كلُّها» — لا كلُّ قائمةٍ لا يصحّ منها شيء.
         *
         * وهنا يفترق هذا عن `StorePage::order` عمدًا: هناك «لا قسمَ على
         * الصفحة» ليس اختيارًا معقولًا — واجهةٌ عاريةٌ بلا صنفٍ ولا فئة.
         * وهنا «لا صفحةَ غير الرئيسية والمتجر» اختيارٌ صحيحٌ يقع كلَّ يوم:
         * محلٌّ يبيع ولا يريد صفحةَ تعريفٍ ولا صفحةَ تواصل.
         */
        if ($raw === '') {
            return self::OPTIONAL;
        }

        return array_values(array_unique(array_filter(
            array_map('trim', explode(',', $raw)),
            fn ($p) => in_array($p, self::OPTIONAL, true),
        )));
    }

    /**
     * أهذه الصفحةُ قائمةٌ الآن؟ — إذنُ صاحبها وما فيها معًا.
     *
     * ويُسأل في المتحكّم قبل الرسم: رابطٌ محفوظٌ إلى صفحةٍ أُطفئت أو فرغت
     * يردّ «غير موجود» لا صفحةً بيضاء.
     */
    public static function has(int $businessId, string $page, string $lang = 'ar'): bool
    {
        return match ($page) {
            self::HOME => true,
            /*
             * و«المتجر» تتبع الرفّ.
             *
             * قاعدةُ زرّ «تسوّق الآن» نفسُها في الواجهة: ما يَعِد ببضاعةٍ لا
             * يُرسم على رفٍّ خالٍ. ولو بقي الرابطُ في القائمة لَقاد كلَّ
             * زائرٍ إلى «لا منتجات هنا بعد».
             */
            self::SHOP => self::shelf($businessId),
            // و«من نحن» بنبذة لغة الصفحة — لا تُفتح الإنجليزيّةُ على نبذةٍ عربيّة
            self::ABOUT => in_array(self::ABOUT, self::allowed($businessId), true)
                && StorePage::about($businessId, $lang) !== '',
            self::CONTACT => in_array(self::CONTACT, self::allowed($businessId), true)
                && self::contactLines($businessId) !== [],
            default => false,
        };
    }

    /**
     * أفي المتجر بضاعةٌ معروضة؟
     *
     * ويُسأل من موضعٍ واحد لأنّ جوابه يُقرأ في اثنين: القائمةُ تُسقط رابطَ
     * «المتجر» على رفٍّ خالٍ، وشاشةُ «الصفحات» تقول لصاحبه لماذا. وسؤالان
     * بصيغتين يفترقان يومًا، فتقول الشاشةُ غيرَ ما يفعل المتجر.
     */
    private static function shelf(int $businessId): bool
    {
        return Product::where('business_id', $businessId)
            ->where('active', true)->where('published', true)->exists();
    }

    /**
     * ما يُكتب في صفحة «تواصل معنا» — ولا تُفتح إن لم يكن فيها سطر.
     *
     * والساعاتُ من إعدادات المتجر لا من الهوية: هي ما كتبه لزبائن موقعه
     * (`store_hours`)، وتُقرأ في التذييل من الموضع نفسه.
     *
     * @return array<string, string>
     */
    public static function contactLines(int $businessId, string $lang = 'ar'): array
    {
        $identity = MerchantData::identity($businessId);
        // بلغة الصفحة — انظر `StorePage::hours`
        $hours = StorePage::hours($businessId, $lang);

        return array_filter([
            'phone' => $identity['phone'],
            'whatsapp' => $identity['whatsapp'],
            'email' => $identity['email'],
            'address' => $identity['address'],
            'hours' => $hours,
        ], fn ($v) => trim((string) $v) !== '');
    }

    /**
     * القائمةُ كما تُرسم — الصفحاتُ القائمةُ وحدَها، بأسمائها وروابطها.
     *
     * ومن كتب قائمتَه تُرسم بترتيبه وروابطِه (`menu`). ورابطٌ يقود إلى ما
     * يقف عليه الزائر بعينه (`$here`) هو المُضاء وحده: من فتح «هدايا التخرّج»
     * من القائمة يرى اسمَها مضاءً لا «المتجر» معها.
     *
     * @param  array<string, string>  $t  نصوصُ الواجهة بلغة الزائر
     * @param  array{type: string, value: string|int}|null  $here  ما يُعرض الآن — `StoreTarget`
     * @return list<array{key: string, label: string, href: string|null, current: bool, external: bool}>
     */
    public static function links(int $businessId, string $base, array $t, string $current = self::HOME, string $lang = 'ar', ?array $here = null): array
    {
        $menu = self::menu($businessId);

        if ($menu !== []) {
            return self::menuLinks($businessId, $menu, $base, $t, $current, $lang, $here);
        }

        $out = [];

        foreach (self::ALL as $page) {
            if (! self::has($businessId, $page, $lang)) {
                continue;
            }

            $out[] = [
                'key' => $page,
                'label' => (string) ($t[self::LABELS[$page]] ?? $page),
                'href' => $base.(self::PATHS[$page] === '/' ? '/' : self::PATHS[$page]),
                'current' => $page === $current,
                'external' => false,
            ];
        }

        /*
         * وقائمةٌ من رابطٍ واحد لا تُرسم.
         *
         * متجرٌ جديد لا نبذةَ فيه ولا بضاعة تبقى له «الرئيسية» وحدَها —
         * وشريطُ تنقّلٍ فيه اسمُ الصفحة التي أنت فيها ليس تنقّلًا.
         */
        return count($out) > 1 ? $out : [];
    }

    /* ═══════════ القائمةُ المرتّبة ═══════════ */

    /** أكتب صاحبُ المتجر قائمتَه؟ — فتُرسم سطرًا يُمرَّر لا أسطرًا تلتفّ */
    public static function customized(int $businessId): bool
    {
        return self::menu($businessId) !== [];
    }

    /**
     * القائمةُ كما حُفظت — أو قائمةٌ فارغة فهي القديمة.
     *
     * والقراءةُ متسامحة: ما لا يُقرأ يسقط، والصفحةُ الأساسيّة التي غابت عنها
     * تُلحق في آخرها بترتيبها الأصليّ — فلا تضيع «الرئيسية» أو «المتجر» من
     * قائمةٍ كُتبت بيدٍ في القاعدة.
     *
     * @return list<array<string, mixed>>
     */
    public static function menu(int $businessId): array
    {
        return self::menuFromJson((string) (MarketingSettings::group($businessId, 'website')[self::MENU] ?? ''));
    }

    /** @return list<array<string, mixed>> */
    public static function menuFromJson(string $raw): array
    {
        $list = $raw === '' ? null : json_decode($raw, true);

        if (! is_array($list) || ! array_is_list($list) || $list === []) {
            return [];
        }

        $out = [];
        $pages = [];
        $ids = [];

        foreach ($list as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (($item['type'] ?? null) === 'page' && in_array($item['key'] ?? null, self::ALL, true) && ! in_array($item['key'], $pages, true)) {
                $pages[] = $item['key'];
                $out[] = ['type' => 'page', 'key' => $item['key']];

                continue;
            }

            $id = is_string($item['id'] ?? null) ? $item['id'] : '';
            $type = $item['type'] ?? null;
            // والعنوانُ بلا وجهة — والرابطُ بوجهةٍ تُقرأ
            $target = $type === 'link' ? StoreTarget::parse($item['target'] ?? null) : null;

            if (! in_array($type, ['link', 'label'], true) || ($type === 'link' && $target === null)
                || preg_match(self::LINK_ID, $id) !== 1 || isset($ids[$id]) || count($ids) >= self::MAX_LINKS) {
                continue;
            }

            $title = [self::text($item['title_ar'] ?? ''), self::text($item['title_en'] ?? '')];

            if ($title === ['', '']) {
                continue;
            }

            $ids[$id] = true;
            $out[] = ['type' => $type, 'id' => $id, 'title_ar' => $title[0], 'title_en' => $title[1]]
                + ($type === 'link' ? ['target' => $target] : [])
                + ['visible' => (bool) ($item['visible'] ?? true)];
        }

        foreach (array_diff(self::ALL, $pages) as $page) {
            $out[] = ['type' => 'page', 'key' => $page];
        }

        return $out;
    }

    /**
     * القائمةُ المكتوبة كما تُرسم.
     *
     * @param  list<array<string, mixed>>  $menu
     * @return list<array{key: string, label: string, href: string|null, current: bool, external: bool}>
     */
    private static function menuLinks(int $businessId, array $menu, string $base, array $t, string $current, string $lang, ?array $here): array
    {
        $links = array_values(array_filter($menu, fn (array $i) => $i['type'] === 'link' && $i['visible']));
        $hrefs = StoreTarget::hrefs($businessId, $base, $lang, array_map(fn (array $i) => $i['target'], $links));
        $hrefOf = [];
        $lit = null;

        foreach ($links as $k => $link) {
            $hrefOf[$link['id']] = $hrefs[$k];

            if ($lit === null && $hrefs[$k] !== null && StoreTarget::same($link['target'], $here)) {
                $lit = $link['id'];
            }
        }

        $out = [];

        foreach ($menu as $item) {
            if ($item['type'] === 'page') {
                $page = $item['key'];

                if (self::has($businessId, $page, $lang)) {
                    $out[] = [
                        'key' => $page,
                        'label' => (string) ($t[self::LABELS[$page]] ?? $page),
                        'href' => $base.(self::PATHS[$page] === '/' ? '/' : self::PATHS[$page]),
                        'current' => $lit === null && $page === $current,
                        'external' => false,
                    ];
                }

                continue;
            }

            // والاسمُ بلغة الصفحة، وإلّا باللغة الأخرى — اسمٌ يُضغط لا جملة
            $mine = $lang === 'en' ? $item['title_en'] : $item['title_ar'];
            $label = $mine !== '' ? $mine : ($lang === 'en' ? $item['title_ar'] : $item['title_en']);

            // والعنوانُ كلمةٌ في القائمة لا رابط — بلا `href` أصلًا، ولا يُضاء
            if ($item['type'] === 'label') {
                if ($item['visible']) {
                    $out[] = ['key' => 'label-'.$item['id'], 'label' => $label, 'href' => null, 'current' => false, 'external' => false];
                }

                continue;
            }

            // والمخفيُّ لا رابطَ له أصلًا (`$links` أعلاه) — ولا ما لا تُفتح وجهتُه
            $href = $hrefOf[$item['id']] ?? null;

            if ($href === null) {
                continue;
            }

            $out[] = [
                'key' => 'link-'.$item['id'],
                'label' => $label,
                'href' => $href,
                'current' => $lit === $item['id'],
                // والرابطُ المكتوبُ إلى خارج المتجر يُفتح في نافذةٍ جديدة
                'external' => StoreTarget::external($href),
            ];
        }

        return count($out) > 1 ? $out : [];
    }

    /**
     * القائمةُ كما أرسلتها الشاشة ← نصُّ JSON يُحفظ — أو رفضٌ برسالة.
     *
     * والصفحاتُ الأربعُ فيها كلُّها مرّةً مرّة: تُرتَّب ولا تُحذف — والغائبةُ
     * منها تُلحق في آخرها. والروابطُ عشرةٌ لا أكثر، لكلٍّ اسمٌ بلغةٍ على الأقلّ
     * ووجهةٌ في متجره (`StoreTarget::checked`). والفراغُ القائمةُ القديمة.
     *
     * @param  list<array<string, mixed>>  $collections  ما يُقاس عليه — ما يُحفظ معها، وإلّا المسوّدة
     * @param  list<array<string, mixed>>  $pages  والصفحاتُ المخصّصة كذلك
     */
    public static function validatedMenu(int $businessId, mixed $raw, array $collections, array $pages): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }

        if (! is_string($raw) || strlen($raw) > self::MENU_RAW_MAX) {
            self::refuse(__('القائمة الرئيسية غير صالحة — أعد فتح المحرّر واحفظ من جديد.'));
        }

        $list = json_decode($raw, true);

        if (! is_array($list) || ! array_is_list($list)) {
            self::refuse(__('القائمة الرئيسية غير صالحة — أعد فتح المحرّر واحفظ من جديد.'));
        }

        $collectionSlugs = array_map(fn (array $c) => $c['slug'], $collections);
        $pageSlugs = array_map(fn (array $p) => $p['slug'], $pages);
        $out = [];
        $seen = [];
        $ids = [];
        $n = 0;

        foreach ($list as $item) {
            if (! is_array($item)) {
                self::refuse(__('القائمة الرئيسية غير صالحة — أعد فتح المحرّر واحفظ من جديد.'));
            }

            if (($item['type'] ?? null) === 'page') {
                $key = $item['key'] ?? null;

                if (! in_array($key, self::ALL, true) || in_array($key, $seen, true)) {
                    self::refuse(__('القائمة الرئيسية غير صالحة — أعد فتح المحرّر واحفظ من جديد.'));
                }

                $seen[] = $key;
                $out[] = ['type' => 'page', 'key' => $key];

                continue;
            }

            $type = $item['type'] ?? null;

            if (! in_array($type, ['link', 'label'], true)) {
                self::refuse(__('القائمة الرئيسية غير صالحة — أعد فتح المحرّر واحفظ من جديد.'));
            }

            $n++;

            if ($n > self::MAX_LINKS) {
                self::refuse(__('أضف حتى :max عناصر في القائمة فقط.', ['max' => self::MAX_LINKS]));
            }

            $id = is_string($item['id'] ?? null) ? $item['id'] : '';

            if (preg_match(self::LINK_ID, $id) !== 1 || isset($ids[$id])) {
                self::refuse(__('القائمة الرئيسية غير صالحة — أعد فتح المحرّر واحفظ من جديد.'));
            }

            $ids[$id] = true;
            $title = ['title_ar' => self::text($item['title_ar'] ?? ''), 'title_en' => self::text($item['title_en'] ?? '')];

            if ($title['title_ar'] === '' && $title['title_en'] === '') {
                self::refuse(__('اكتب اسمًا للعنصر :n في القائمة — بالعربية أو بالإنجليزية.', ['n' => $n]));
            }

            foreach ($title as $text) {
                if (mb_strlen($text) > self::LINK_TITLE_MAX) {
                    self::refuse(__('اسم العنصر :n في القائمة أطول من :max حرفًا.', ['n' => $n, 'max' => self::LINK_TITLE_MAX]));
                }
            }

            // والعنوانُ اسمٌ وحده — لا وجهةَ تُطلب منه ولا تُحفظ له
            if ($type === 'label') {
                $out[] = ['type' => 'label', 'id' => $id] + $title + [
                    'visible' => filter_var($item['visible'] ?? true, FILTER_VALIDATE_BOOL),
                ];

                continue;
            }

            [$target, $why] = StoreTarget::checked($businessId, $item['target'] ?? null, $collectionSlugs, $pageSlugs, StoreTarget::LINKS);

            if ($why === 'type') {
                self::refuse(__('اختر ما يفتحه الرابط :n — صفحةً أو مجموعةً أو فئةً من متجرك.', ['n' => $n]));
            }

            if ($why === 'url') {
                self::refuse(__('الرابط :n غير صالح — اكتب رابطًا يبدأ بـ https:// أو بـ /.', ['n' => $n]));
            }

            if ($target === null) {
                self::refuse(__('ما يفتحه الرابط :n ليس في متجرك — اختر وجهته من جديد.', ['n' => $n]));
            }

            $out[] = ['type' => 'link', 'id' => $id] + $title + [
                'target' => $target,
                'visible' => filter_var($item['visible'] ?? true, FILTER_VALIDATE_BOOL),
            ];
        }

        // والصفحاتُ الأساسيّة لا تُحذف من القائمة — الغائبةُ منها تُلحق بترتيبها
        foreach (array_diff(self::ALL, $seen) as $page) {
            $out[] = ['type' => 'page', 'key' => $page];
        }

        // وقائمةٌ هي القديمةُ نفسُها بترتيبها وبلا رابطٍ تُحفظ فراغًا — فتبقى كما كانت
        if ($out === array_map(fn (string $p) => ['type' => 'page', 'key' => $p], self::ALL)) {
            return '';
        }

        return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function refuse(string $message): never
    {
        throw ValidationException::withMessages([self::MENU => $message]);
    }

    /**
     * صفحاتُ المتجر كما تقرؤها شاشةُ «الصفحات» في لوحة صاحبه.
     *
     * ═══ و«مُشغَّلةٌ ولا تظهر» تُقال في صفّها ═══
     *
     * قاعدةُ `PageEditor::SILENT` نفسُها: صفحةٌ أذِن بها صاحبُها ولا يجدها
     * في متجره حالةٌ يظنّ فيها العطبَ في النظام. والسببُ عندنا مكتوب —
     * نبذةٌ لم تُكتب، أو رفٌّ خالٍ — فلا يُترك يُخمَّن.
     *
     * ولا يُقال ذلك لصفحةٍ أطفأها بيده: هو يعرف لمَ أطفأها.
     *
     * @return list<array{key: string, path: string, fixed: bool, on: bool, blocked: ?string}>
     */
    public static function rows(int $businessId): array
    {
        $allowed = self::allowed($businessId);

        $why = [
            self::HOME => null,
            self::SHOP => self::shelf($businessId)
                ? null
                : 'لا صنفَ معروضًا في متجرك — فلا رفَّ تُفتح عليه.',
            // ويُقال «لا تظهر» إن لم تُكتب بأيّ لغة — ومكتوبةٌ بإحداهما تظهر بها
            self::ABOUT => StorePage::about($businessId, 'ar') !== '' || StorePage::about($businessId, 'en') !== ''
                ? null
                : 'اكتب نبذتك لتُفتح الصفحة — وهي نفسُها التي تظهر في قسم «عنّا».',
            self::CONTACT => self::contactLines($businessId) !== []
                ? null
                : 'لا هاتفَ ولا بريدَ ولا عنوانَ ولا ساعاتِ عمل — ولا شيءَ تقوله الصفحة.',
        ];

        return array_map(function (string $page) use ($allowed, $why) {
            $fixed = ! in_array($page, self::OPTIONAL, true);
            $on = $fixed || in_array($page, $allowed, true);

            return [
                'key' => $page,
                'path' => self::PATHS[$page],
                'fixed' => $fixed,
                'on' => $on,
                'blocked' => $on ? $why[$page] : null,
            ];
        }, self::ALL);
    }
}
