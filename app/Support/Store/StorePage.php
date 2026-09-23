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
    public const SECTIONS = ['cats', 'best', 'new', 'banner', 'block', 'about', 'reviews'];

    /** أكثرُ ما يُبرزه من الأصناف — أربعةٌ كما يتّسع الصفّ */
    public const MAX_FEATURED = 4;

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

        $picked = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $raw)),
            fn ($s) => in_array($s, self::SECTIONS, true),
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
        return $picked ?: self::SECTIONS;
    }

    /** أيُعرض هذا القسم؟ */
    public static function shows(int $businessId, string $section): bool
    {
        return in_array($section, self::order($businessId), true);
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
     * @return array{title: string, text: string, image: ?string, cta: ?string, href: ?string}|null
     */
    public static function block(int $businessId): ?array
    {
        $site = MarketingSettings::group($businessId, 'website');

        if ((string) ($site['store_block_on'] ?? '0') !== '1') {
            return null;
        }

        $title = trim((string) ($site['store_block_title'] ?? ''));
        $text = trim((string) ($site['store_block_text'] ?? ''));

        if ($title === '' || $text === '') {
            return null;
        }

        $cta = trim((string) ($site['store_block_cta'] ?? ''));
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
        $raw = trim((string) (MarketingSettings::group($businessId, 'website')['store_featured'] ?? ''));

        // والفراغُ يؤول إلى `[]` بالترشيح نفسِه — فلا فحصَ ثانٍ له
        $ids = array_values(array_unique(array_filter(
            array_map('intval', explode(',', $raw)),
            fn ($id) => $id > 0,
        )));

        return array_slice($ids, 0, self::MAX_FEATURED);
    }
}
