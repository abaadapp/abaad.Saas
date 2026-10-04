<?php

namespace App\Support\Store;

use App\Support\MarketingSettings;

/**
 * صفحةُ المتجر — ما فيها وترتيبُه، كما يضبطه صاحبُه بلا أن يسأل أحدًا.
 *
 * ═══ والفراغُ يعني «ما كان» ═══
 *
 * القاعدةُ نفسُها في `CheckoutFields`: مفتاحٌ لم يُكتب يُقرأ بما كان
 * المتجرُ يعرضه قبل هذه الشاشة. فترقيةٌ تُنزل تسعةَ مفاتيحَ فارغةٍ على كلّ
 * متجرٍ في أبعاد لا تُحرّك في صفحته شيئًا — لا تُخفي قسمًا ولا تقلب ترتيبًا.
 */
final class StorePage
{
    /**
     * أقسامُ الصفحة بترتيبها الأصليّ.
     *
     * والواجهةُ ليست فيها: هي هويّةُ الصفحة لا قسمًا يُطفأ — متجرٌ يفتح
     * على «تسوّق حسب الفئة» بلا عنوانٍ ولا صورةٍ ولا زرٍّ ليس متجرًا.
     *
     * و`block` — القسمُ الذي يكتبه بنفسه — في الترتيب منذ البداية وإن كان
     * مُطفأً: فمن فتحه ولم يمسّ الترتيبَ يجده في موضعه المعقول.
     */
    public const DEFAULT_ORDER = ['cats', 'best', 'new', 'banner', 'block', 'about', 'reviews'];

    /**
     * وكلُّ قسمٍ يعرفه القالب — الأصليّةُ ثمّ «اختيارات RIBBON».
     *
     * و`picks` ليس في الترتيب الأصليّ عمدًا: الفراغُ يُقرأ «ما كان»، فلو
     * دخله لَظهر في كلّ متجرٍ لم يرتّب صفحتَه. فيبقى مطفأً حتّى يُرفع بيده،
     * ولمن في قائمته وحده (`RibbonPicks::allowed`).
     */
    public const SECTIONS = [...self::DEFAULT_ORDER, RibbonPicks::SECTION];

    /** أكثرُ ما يُبرزه من الأصناف — أربعةٌ كما يتّسع الصفّ */
    public const MAX_FEATURED = 4;

    /** وكم صنفًا يعرض «وصل حديثًا» — تلقائيًّا كان أو يدويًّا */
    public const NEW_ARRIVALS = 4;

    /** «وصل حديثًا»: أحدثُ الأصناف بالمعرّف — وهو الأصل */
    public const NEW_AUTO = 'auto';

    /** «وصل حديثًا»: ما اختاره بيده، بترتيبه */
    public const NEW_MANUAL = 'manual';

    /* ═══════════ الأقسام ═══════════ */

    /**
     * أقسامُ الصفحة كما اختارها — مرتَّبةً، وما أُطفئ خارجٌ منها.
     *
     * والقائمةُ هي الترتيبُ والظهورُ معًا: ما فيها يُعرض بترتيبه، وما ليس
     * فيها لا يُعرض. ومفتاحان — واحدٌ للترتيب وآخرُ للظهور — يفترقان يومًا،
     * فيبقى في الترتيب قسمٌ أُطفئ ويُقرأ الترتيبُ خطأً.
     *
     * @return list<string>
     */
    public static function order(int $businessId): array
    {
        $raw = trim((string) (MarketingSettings::group($businessId, 'website')['store_sections'] ?? ''));

        // و«اختيارات RIBBON» لمن فُتحت له وحده — ومن ليس في قائمتها لا يُضمّ له ولو حُفظ
        $known = RibbonPicks::allowed($businessId) ? self::SECTIONS : self::DEFAULT_ORDER;

        $picked = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $raw)),
            fn ($s) => in_array($s, $known, true),
        )));

        /*
         * ═══ وسطرٌ واحد يحمل الحالتين ═══
         *
         * الفراغُ («ما كان») وقائمةٌ لا تصحّ منها واحدة يؤولان إلى `[]`
         * بعد الترشيح، ويُردّان معًا إلى الأصل.
         *
         * وكان فوقه فحصٌ صريحٌ للفراغ. وهو وهذا يقولان الشيء نفسَه —
         * ونجا من الطفرات: عُطِّل فلم يتغيّر شيء. فحارسان لسؤالٍ واحد
         * يفترقان يومًا، ويُظنّ المرفوعُ منهما يحرس.
         *
         * والأثرُ أنّ إعدادًا حُفظ خطأً لا يجعل الصفحةَ واجهةً عاريةً بلا
         * صنفٍ ولا فئة — وصفحةٌ فارغةٌ تُفقد الزبونَ ثقتَه فلا يعود.
         */
        return $picked ?: self::DEFAULT_ORDER;
    }

    /** أيُعرض هذا القسم؟ */
    public static function shows(int $businessId, string $section): bool
    {
        return in_array($section, self::order($businessId), true);
    }

    /* ═══════════ عنوانُ الواجهة ووصفُها ═══════════ */

    /**
     * عنوانُ الواجهة بلغة الصفحة — ما كتبه لها، وإلّا ما كان.
     *
     * ═══ وما كان ═══
     *
     * للعربيّة: `store_headline` («العنوان الكبير» في المحرّر قبل هذا)، ثمّ
     * نصُّ القالب. وللإنجليزيّة: نصُّ القالب الإنجليزيّ وحده — فلا يُكتب
     * عنوانٌ عربيٌّ في صفحةٍ إنجليزيّة، ولا إنجليزيٌّ في عربيّة.
     *
     * @param  array<string, string>  $t  نصوصُ القالب بلغة الصفحة (`RibbonTexts::for`)
     */
    public static function heroTitle(int $businessId, string $lang, array $t): string
    {
        $site = MarketingSettings::group($businessId, 'website');

        if ($lang === 'en') {
            return self::text($site['store_hero_title_en'] ?? '') ?? $t['heroTitle'];
        }

        return self::text($site['store_hero_title'] ?? '')
            ?? self::text($site['store_headline'] ?? '')
            ?? $t['heroTitle'];
    }

    /** ووصفُها — بالقاعدة نفسِها، ولا سابقَ له غيرُ نصّ القالب */
    public static function heroSub(int $businessId, string $lang, array $t): string
    {
        $key = $lang === 'en' ? 'store_hero_sub_en' : 'store_hero_sub';

        return self::text(MarketingSettings::group($businessId, 'website')[$key] ?? '') ?? $t['heroSub'];
    }

    /** نصٌّ مكتوبٌ بعد التشذيب — أو `null` لفراغه */
    private static function text(mixed $raw): ?string
    {
        $text = trim((string) $raw);

        return $text !== '' ? $text : null;
    }

    /* ═══════════ النبذة ═══════════ */

    /**
     * نبذةُ النشاط بلغة الصفحة — `store_about` للعربيّة و`store_about_en`
     * للإنجليزيّة. وفارغُ اللغة فراغٌ: لا قسمَ «عنّا» ولا صفحةَ «من نحن» ولا
     * سطرَ تذييلٍ بها في تلك اللغة — لا تُعرض فيها نبذةُ الأخرى.
     *
     * ويُقرأ كلُّ ما في RIBBON منها من هنا: القسم، والصفحة، والتذييل،
     * ووصفُ البحث المحسوب (`StoreSeo::head`)، وإذنُ صفحة «من نحن» (`StoreNav::has`).
     */
    public static function about(int $businessId, string $lang = 'ar'): string
    {
        $key = $lang === 'en' ? 'store_about_en' : 'store_about';

        return trim((string) (MarketingSettings::group($businessId, 'website')[$key] ?? ''));
    }

    /* ═══════════ صورةُ الواجهة ═══════════ */

    /**
     * صورةُ الواجهة كما اختارها — أو `null` فتبقى القاعدةُ القديمة.
     *
     * ═══ ولمَ تُختار ═══
     *
     * كانت تُؤخذ من أوّل صنفٍ في «الأكثر مبيعًا» — أي أنّ أوّلَ ما تقع عليه
     * عينُ الزبون **صدفة**. محلُّ وردٍ يبيع ورودَ عزاءٍ في أسبوع فتصير
     * صورةَ متجره شهرًا. وصاحبُه يراها ولا يعرف من أين جاءت ولا كيف يبدّلها.
     */
    public static function heroImage(int $businessId): ?string
    {
        $raw = trim((string) (MarketingSettings::group($businessId, 'website')['store_hero_image'] ?? ''));

        return $raw !== '' ? $raw : null;
    }

    /**
     * صورةُ شريط المناسبات — أو `null` فتبقى الخطوطُ المرسومة.
     *
     * والشريطُ يَعِد بباقاتٍ وكرتِ هدية، وكان يُرسم إلى جانب وعده مستطيلٌ
     * مخطَّط بالـCSS. فيقرأ الزبون وعدًا ولا يرى منه شيئًا.
     */
    public static function bannerImage(int $businessId): ?string
    {
        $raw = trim((string) (MarketingSettings::group($businessId, 'website')['store_banner_image'] ?? ''));

        return $raw !== '' ? $raw : null;
    }

    /**
     * سطرُ التذييل — أو `null` فلا يُرسم سطر.
     *
     * كان الفراغُ يقع على وصفِ محلٍّ بعينه مكتوبٍ في القالب، فمحلٌّ آخر يلبس
     * الواجهةَ نفسَها يُذيّل صفحتَه بوصفِ غيره. ولا بديلَ يُخترع اليوم.
     */
    public static function tagline(int $businessId, string $lang = 'ar'): ?string
    {
        // ولكلّ لغةٍ سطرُها — وفارغُها لا يقع على الأخرى فتختلط اللغتان
        $key = $lang === 'en' ? 'store_tagline_en' : 'store_tagline';
        $raw = trim((string) (MarketingSettings::group($businessId, 'website')[$key] ?? ''));

        return $raw !== '' ? $raw : null;
    }

    /**
     * ساعاتُ العمل بلغة الصفحة — `store_hours` للعربيّة و`store_hours_en`
     * للإنجليزيّة. وفارغُ اللغة فراغٌ: «يوميًا 10 ص» لا تُكتب في صفحةٍ إنجليزيّة.
     */
    public static function hours(int $businessId, string $lang = 'ar'): string
    {
        $key = $lang === 'en' ? 'store_hours_en' : 'store_hours';

        return trim((string) (MarketingSettings::group($businessId, 'website')[$key] ?? ''));
    }

    /* ═══════════ القسمُ الذي يكتبه بنفسه ═══════════ */

    /**
     * قسمٌ حرٌّ: عنوانٌ ونصٌّ وصورةٌ وزرّ — أو `null` إن لم يُفتح أو لم يُملأ.
     *
     * وبه يقول ما لا تقوله بضاعتُه: «اشتراك الورد الشهريّ»، «تنسيق
     * الأعراس»، «نوصّل إلى صلالة الخميس». وكان كلُّ واحدةٍ من هذه تعني
     * رسالةً إلى من يبني له الموقع.
     *
     * ولا يُعرض بعنوانٍ بلا نصٍّ ولا بنصٍّ بلا عنوان: نصفُ قسمٍ على صفحةٍ
     * أسوأُ من لا شيء.
     *
     * ═══ ولكلّ لغةٍ نصُّها ═══
     *
     * العنوانُ والنصُّ والزرُّ من مفاتيح اللغة (`_en` للإنجليزيّة)، والصورةُ
     * والوجهةُ واحدتان. ولا تقع لغةٌ على أخرى: صفحةٌ إنجليزيّةٌ لم يُكتب
     * لها القسمُ لا يُرسم فيها — لا يُعرض فيها نصُّه العربيّ.
     *
     * @return array{title: string, text: string, image: ?string, cta: ?string, href: ?string}|null
     */
    public static function block(int $businessId, string $lang = 'ar'): ?array
    {
        $site = MarketingSettings::group($businessId, 'website');

        if ((string) ($site['store_block_on'] ?? '0') !== '1') {
            return null;
        }

        $suffix = $lang === 'en' ? '_en' : '';
        $title = trim((string) ($site['store_block_title'.$suffix] ?? ''));
        $text = trim((string) ($site['store_block_text'.$suffix] ?? ''));

        if ($title === '' || $text === '') {
            return null;
        }

        $cta = trim((string) ($site['store_block_cta'.$suffix] ?? ''));
        $href = trim((string) ($site['store_block_href'] ?? ''));

        return [
            'title' => $title,
            'text' => $text,
            'image' => ($i = trim((string) ($site['store_block_image'] ?? ''))) !== '' ? $i : null,
            // ولا زرَّ بلا وجهة، ولا وجهةَ بلا اسمٍ يُضغط
            'cta' => $cta !== '' && $href !== '' ? $cta : null,
            'href' => $cta !== '' && $href !== '' ? $href : null,
        ];
    }

    /* ═══════════ المختارات ═══════════ */

    /**
     * أصنافٌ يُبرزها بيده — أو `[]` فيبقى «الأكثر مبيعًا» محسوبًا.
     *
     * والحسبةُ تتأخّر عن المواسم دائمًا: باقاتُ العيد تُبرَز بعد أن تبيع،
     * وقد مضى العيد. فمن أراد أن يُصدّرها قبلها اختارها.
     *
     * @return list<int>
     */
    public static function featured(int $businessId): array
    {
        return self::ids(MarketingSettings::group($businessId, 'website')['store_featured'] ?? '', self::MAX_FEATURED);
    }

    /* ═══════════ وصل حديثًا ═══════════ */

    /**
     * أتلقائيٌّ هو أم يدويّ — كما حُفظ، وكلُّ ما سوى `manual` تلقائيّ.
     *
     * والقراءةُ وحدها هنا: من يُسمح له باليدويّ سؤالٌ آخر في `NewArrivals`.
     */
    public static function newArrivalsMode(int $businessId): string
    {
        return self::mode(MarketingSettings::group($businessId, 'website')['store_new_arrivals_mode'] ?? '');
    }

    /** ما اختاره بيده بترتيبه — معرّفاتٌ موجبةٌ فريدة، أربعةٌ لا أكثر */
    public static function newArrivalIds(int $businessId): array
    {
        return self::ids(MarketingSettings::group($businessId, 'website')['store_new_arrivals'] ?? '', self::NEW_ARRIVALS);
    }

    /** قيمةُ الطريقة المخزّنة ← `auto` أو `manual` */
    public static function mode(?string $raw): string
    {
        return $raw === self::NEW_MANUAL ? self::NEW_MANUAL : self::NEW_AUTO;
    }

    /**
     * قائمةُ معرّفاتٍ مفصولةٍ بفواصل ← معرّفاتٌ موجبةٌ فريدة بترتيبها، إلى الحدّ.
     *
     * قارئٌ واحدٌ للمختارات ولـ«وصل حديثًا»: فلا يُفصَّل النصُّ في المتحكّم
     * وفي الشاشة وفي الواجهة بثلاث قواعد.
     *
     * @return list<int>
     */
    public static function ids(?string $raw, int $max): array
    {
        // والفراغُ يؤول إلى `[]` بالترشيح نفسِه — فلا فحصَ ثانٍ له
        $ids = array_values(array_unique(array_filter(
            array_map('intval', explode(',', trim((string) $raw))),
            fn ($id) => $id > 0,
        )));

        return array_slice($ids, 0, $max);
    }
}
