<?php

namespace App\Support;

use App\Models\Setting;

/**
 * إعدادات أدوات التسويق — مفاتيحها وقيمها الافتراضية في موضعٍ واحد.
 *
 * كانت تُقرأ بمفاتيحَ نصّية متناثرة في المتحكّمات والقوالب، ولكلٍّ منها قيمة
 * افتراضية مكتوبة عند موضع القراءة. فحرفٌ يسقط من مفتاح لا يُخطئ أحدًا: تُقرأ
 * القيمة الافتراضية بهدوء وتبدو الشاشة سليمة، والإعداد الذي حفظه التاجر لا
 * أثر له.
 *
 * والمفاتيح هنا تُقرأ وتُكتب من هذا الباب وحده، فالافتراضيّ واحدٌ في الطرفين.
 */
class MarketingSettings
{
    /** المجموعات ومفاتيحها وقيمها الافتراضية */
    public const GROUPS = [
        'website' => [
            'site_enabled' => '0',
            'site_domain' => '',
            'site_tagline' => '',
            'site_about' => '',
            'site_whatsapp' => '',
            'site_instagram' => '',
            'site_show_prices' => '1',
            'site_allow_orders' => '0',
        ],
        'seo' => [
            'seo_title' => '',
            'seo_description' => '',
            'seo_keywords' => '',
            'seo_index' => '1',
            'seo_ga_id' => '',
        ],
        /*
         * أربعةُ مقابضَ لا أكثر — وكلٌّ منها يقرؤه المُرسِل.
         *
         * كانت المجموعة تحمل خمسةً أخرى بقيت من شاشة «افتح محادثة» بعد أن
         * حُذفت الشاشة: `wa_enabled` و`wa_number` وثلاثةُ نصوص رسائل. تُعرض
         * للتاجر مقابضَ كاملة — يُطفئ الإشعارات، ويكتب رقمه، ويصوغ نصّ
         * الرسالة ويعاينها على الشاشة — **ولا يقرأ منها أحدٌ حرفًا**.
         *
         * والإطفاء الحقيقيّ في عمود `businesses.whatsapp_enabled` يُدار من
         * بطاقة الوصلة فوقها، والرقم رقمُ الوصلة المعتمدة عند ميتا، والنصّ
         * قالبٌ معتمَدٌ باسمه (انظر WhatsAppTemplates) — لأنّ ميتا لا تقبل
         * نصًّا حرًّا في رسالةٍ يبدؤها العمل.
         *
         * فمقبضٌ لا يُدير شيئًا أسوأ من غياب المقبض: التاجر يُطفئ الإشعارات
         * ويطمئنّ، وهي تُرسل.
         */
        'whatsapp' => [
            'wa_on_order' => '1',
            'wa_on_ready' => '1',
            'wa_on_out_for_delivery' => '1',
            'wa_on_delivered' => '0',
        ],
        'loyalty' => [
            'loyalty_enabled' => '1',
            'loyalty_earn_rate' => '5',
            'loyalty_redeem_max_pct' => '50',
            'loyalty_redeem_min' => '100',
        ],
    ];

    /** قيم مجموعةٍ كما هي محفوظة — والناقص يعود بافتراضيّه */
    public static function group(int $businessId, string $group): array
    {
        $defaults = self::GROUPS[$group] ?? [];
        $saved = Setting::where('business_id', $businessId)
            ->whereIn('key', array_keys($defaults))->pluck('value', 'key')->all();

        $out = [];
        foreach ($defaults as $key => $default) {
            // القيمة المحفوظة فارغةً قصدٌ لا غياب: لا تُستبدل بالافتراضيّ
            $out[$key] = array_key_exists($key, $saved) && $saved[$key] !== null ? $saved[$key] : $default;
        }

        return $out;
    }

    /** يحفظ ما يخصّ المجموعة من المُدخَل، ويتجاهل ما ليس منها */
    public static function save(int $businessId, string $group, array $values): void
    {
        $allowed = array_keys(self::GROUPS[$group] ?? []);

        foreach ($values as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                continue;
            }

            Setting::updateOrCreate(
                ['business_id' => $businessId, 'key' => $key],
                ['value' => is_bool($value) ? ($value ? '1' : '0') : (string) ($value ?? '')],
            );
        }
    }
}
