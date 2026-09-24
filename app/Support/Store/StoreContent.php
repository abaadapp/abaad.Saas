<?php

namespace App\Support\Store;

use App\Models\StoreSite;
use App\Support\MarketingSettings;

/**
 * عقدُ نشر الواجهة الخاصّة: ما الذي يُجمَّد، وما الذي يبقى حيًّا.
 *
 * ═══ الخطُّ الذي يُرسَم هنا ═══
 *
 * إعداداتُ المتجر ثمانيةٌ وأربعون مفتاحًا في سلّةٍ واحدة، وفيها نوعان لا
 * يُخلطان:
 *
 *  • **ما يراه الزائر فقط** — نصٌّ وصورةٌ وترتيبُ أقسام. هذا يُحرَّر في
 *    مسوّدةٍ ويُنشر حين يرضى صاحبُه. فيكتب نصفَ نبذةٍ ثمّ ينشغل، ولا يقرأ
 *    زبونُه نصفَ نبذة.
 *
 *  • **ما يُغيّر ما يدفعه الزبون أو ما يصل إليه** — رسمُ التوصيل، والمناطق،
 *    والمواعيد، ووسائلُ الدفع، ورقمُ واتساب، وساعاتُ العمل. هذا يسري فورًا.
 *
 * ولمَ لا يُجمَّد الثاني: لو دخل رسمُ التوصيل في النشر لَغيّره التاجرُ
 * صباحًا وبقي القديمُ **يُحصَّل من الزبائن** حتّى ينشر. وذلك خطأٌ ماليّ لا
 * بصريّ — وأثرُه لا يُرى في الشاشة بل في الفاتورة.
 *
 * ولمَ يبقى `store_seo_index` حيًّا: «لا تُفهرسني» طلبٌ يُستعجَل — من كشف
 * صفحةً قبل أوانها يريد إخفاءها الآن لا بعد نشرة.
 *
 * ولمَ يبقى `store_on` حيًّا: هو مفتاحُ الخدمة نفسِها. ولو جُمِّد لَدار
 * المنطقُ على نفسه — مفتاحُ النشر لا يُنشَر.
 *
 * ولمَ تبقى `store_whatsapp` و`store_hours` و`store_instagram` حيّةً وهي
 * في المحرّر: الأوّلُ يُبنى منه زرُّ التواصل (`Storefront::whatsappLink`)،
 * والثاني يُقرأ في **إتمام الطلب** (`WebCheckout::settings`)، والثالثُ
 * رابطٌ في التذييل. وثلاثتُها بيانُ تواصلٍ: رقمٌ خطأ أو ساعةٌ خطأ يجب أن
 * يُصحَّحا الآن، لا أن ينتظرا نشرة.
 *
 * ═══ وما لا يدخل هنا أصلًا ═══
 *
 * السعرُ والمخزونُ والأصنافُ والطلبات. تلك حالُ النشاط الآن تُقرأ عند
 * العرض — فنشرُ تصميمٍ لا يُجمّد بضاعةً ولا يُعيد سعرًا قديمًا. وهي
 * القاعدةُ المكتوبة في `Website\Publication` نفسُها.
 */
final class StoreContent
{
    /**
     * ما يُحرَّر في مسوّدةٍ ويُنشر — ثمانيةَ عشرَ مفتاحًا.
     *
     * @var list<string>
     */
    public const VERSIONED = [
        // نصوصُ الصفحة
        'store_headline', 'store_about', 'store_tagline',
        // صورُها
        'store_hero_image', 'store_banner_image', 'store_about_image',
        // ما يُعرض وبأيّ ترتيب
        'store_featured', 'store_sections', 'store_pages',
        // القسمُ الحرّ
        'store_block_on', 'store_block_title', 'store_block_text',
        'store_block_image', 'store_block_cta', 'store_block_href',
        // وما يقرؤه غوغل من نصّ — أمّا إذنُ الفهرسة فيسري فورًا
        'store_seo_title', 'store_seo_desc',
    ];

    /**
     * القالبُ لا يُنشر ولا يُستعاد.
     *
     * لقطةٌ قديمة قد تحمل قالبًا غيرَ الذي يلبسه المتجر اليوم، واستعادتُها
     * تُبدّل تصميمَ متجرٍ يعمل بلا أن يطلب صاحبُه تبديلًا — وهو أبعدُ ما
     * يكون عن «استرجاعٍ آمن». فيُكتب في اللقطة ليُقرأ، ولا يُكتب منها.
     */
    public const READ_ONLY = ['store_theme'];

    /** أهذا المتجرُ على نظام المسوّدات؟ — ومن لا صفَّ له يعمل كما كان */
    public static function usesDrafts(int $businessId): bool
    {
        return StoreSite::where('business_id', $businessId)->exists();
    }

    /**
     * ما يراه الزائرُ الآن — المنشور.
     *
     * @return array<string, string>
     */
    public static function live(int $businessId): array
    {
        return self::only(MarketingSettings::group($businessId, 'website'));
    }

    /**
     * ما يحرّره صاحبُه الآن.
     *
     * ومن لا مسوّدةَ له يقرأ المنشور: متجرٌ لم يُرحَّل بعد لا يرى فراغًا
     * في محرّره.
     *
     * @return array<string, string>
     */
    public static function draft(int $businessId): array
    {
        $site = StoreSite::where('business_id', $businessId)->first();

        if ($site === null) {
            return self::live($businessId);
        }

        // والناقصُ من المسوّدة يُقرأ من المنشور — مفتاحٌ يُضاف غدًا لا يخرج فارغًا
        return self::only(array_merge(
            MarketingSettings::group($businessId, 'website'),
            self::only((array) $site->draft),
        ));
    }

    /**
     * أيُّ المفاتيح تفترق عن المنشور — وهي «تغييراتٌ غير منشورة».
     *
     * ولا يُكتفى بمقارنة المراجعة: حفظٌ يُعيد القيمةَ إلى ما كانت يزيد
     * المراجعةَ ولا يُغيّر شيئًا، فتقول اللوحةُ «فيه تغييرات» عن لا شيء.
     *
     * @return list<string>
     */
    public static function changed(int $businessId): array
    {
        if (! self::usesDrafts($businessId)) {
            return [];
        }

        $live = self::live($businessId);
        $draft = self::draft($businessId);
        $out = [];

        foreach (self::VERSIONED as $key) {
            if (($live[$key] ?? '') !== ($draft[$key] ?? '')) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * يُصفّي ما ليس من العقد.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public static function only(array $values): array
    {
        $out = [];

        foreach (self::VERSIONED as $key) {
            if (array_key_exists($key, $values) && is_scalar($values[$key])) {
                $out[$key] = (string) $values[$key];
            }
        }

        return $out;
    }

    /**
     * ويُقسَم ما وصل من شاشةٍ إلى نصفين: ما يُنشر وما يسري فورًا.
     *
     * @param  array<string, mixed>  $values
     * @return array{0: array<string, string>, 1: array<string, mixed>}  [المسوّدة, الحيّ]
     */
    public static function split(array $values): array
    {
        $versioned = self::only($values);

        foreach (array_keys($versioned) as $key) {
            unset($values[$key]);
        }

        return [$versioned, $values];
    }
}
