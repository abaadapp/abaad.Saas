<?php

namespace App\Support\Store;

use App\Models\Product;
use App\Support\MarketingSettings;
use App\Support\Website\MerchantData;

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
    public static function has(int $businessId, string $page): bool
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
            self::ABOUT => in_array(self::ABOUT, self::allowed($businessId), true)
                && MerchantData::identity($businessId)['about'] !== '',
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
    public static function contactLines(int $businessId): array
    {
        $identity = MerchantData::identity($businessId);
        $hours = trim((string) (MarketingSettings::group($businessId, 'website')['store_hours'] ?? ''));

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
     * @param  array<string, string>  $t  نصوصُ الواجهة بلغة الزائر
     * @return list<array{key: string, label: string, href: string, current: bool}>
     */
    public static function links(int $businessId, string $base, array $t, string $current = self::HOME): array
    {
        $out = [];

        foreach (self::ALL as $page) {
            if (! self::has($businessId, $page)) {
                continue;
            }

            $out[] = [
                'key' => $page,
                'label' => (string) ($t[self::LABELS[$page]] ?? $page),
                'href' => $base.(self::PATHS[$page] === '/' ? '/' : self::PATHS[$page]),
                'current' => $page === $current,
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
            self::ABOUT => MerchantData::identity($businessId)['about'] !== ''
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
