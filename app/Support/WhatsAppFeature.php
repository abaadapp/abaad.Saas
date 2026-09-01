<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Setting;

/**
 * من يملك أن يفعل ماذا — الإذن في موضعٍ واحد.
 *
 * ولا يُسأل عن اسم الباقة أبدًا. `if ($plan->name === 'احترافية')` يعني أنّ
 * إعادة تسمية باقةٍ تُطفئ ميزةً عند كلّ من فيها، وأنّ منح الميزة لتاجرٍ واحد
 * يستلزم نقله إلى باقةٍ كاملة. فالسؤال «هل هذا المتجر مأذونٌ له؟» لا «ما اسم
 * باقته؟» — والجواب من صفّ المتجر، يكتبه مدير المنصّة ببيعٍ أو بمنحة.
 *
 * وهذا أضيق ما يُنفَّذ اليوم وهو يكفي: حين تُبنى إضافاتٌ تُباع بدورة حياة
 * كاملة، يُوسَّع هذا الحلّال ليقرأ منها — ولا يتغيّر سطرٌ فيمن يسأله.
 */
class WhatsAppFeature
{
    /** إعداد المنصّة: هل واتساب مفعَّل أصلًا في كلّ النظام */
    public const GLOBAL_KEY = 'whatsapp_enabled';

    /** إعداد المنصّة: هل الرقم المشترك مسموحٌ باستعماله */
    public const SHARED_KEY = 'whatsapp_shared_enabled';

    /** مفتاح الميزة المدفوعة — يُقرأ من صفّ المتجر ويُباع لاحقًا كإضافة */
    public const OWN_NUMBER = 'whatsapp_own_number';

    private static function platformFlag(string $key, bool $default): bool
    {
        $raw = Setting::whereNull('business_id')->where('key', $key)->value('value');

        return $raw === null || $raw === '' ? $default : $raw !== '0';
    }

    /** واتساب مفعَّل على مستوى المنصّة كلّها */
    public static function globallyEnabled(): bool
    {
        return self::platformFlag(self::GLOBAL_KEY, false);
    }

    /** الرقم المشترك مسموحٌ به — إطفاؤه يوقف المشترك ولا يوقف من يملك رقمه */
    public static function sharedEnabled(): bool
    {
        return self::platformFlag(self::SHARED_KEY, true);
    }

    /** هل يملك هذا المتجر ربط رقمه الخاص؟ */
    public static function canUseOwnNumber(Business $business): bool
    {
        return (bool) $business->whatsapp_own_allowed;
    }

    /**
     * الوضع الفعّال — وهو غير الوضع المحفوظ.
     *
     * متجرٌ اختار رقمه ثمّ سُحبت منه الميزة يبقى في صفّه `business_own`؛
     * والوصلة لا تُحذف ولا يُعبَث بالعمود. لكنّه لا يُرسل من رقمه، ولا
     * يُدفَع إلى الرقم المشترك بصمت — يقف. وسحبُ إذنٍ لا يُتلف بيانات:
     * إعادةُ المنح تُعيد كلّ شيء كما كان.
     */
    public static function effectiveMode(Business $business): string
    {
        if ($business->whatsapp_mode === WhatsAppMode::BUSINESS_OWN && self::canUseOwnNumber($business)) {
            return WhatsAppMode::BUSINESS_OWN;
        }

        return $business->whatsapp_mode === WhatsAppMode::BUSINESS_OWN
            ? WhatsAppMode::BUSINESS_OWN   // مأذونٌ سُحب إذنه: يبقى في وضعه ويُمتنع، لا يُحوَّل
            : WhatsAppMode::ABAAD_SHARED;
    }

    /**
     * هل يُسمح بالإرسال الآن؟ ولمَ لا إن لم يُسمح.
     *
     * @return string|null سبب المنع، أو null إن سلِم
     */
    public static function blockReason(Business $business): ?string
    {
        if (! self::globallyEnabled()) {
            return WhatsAppStatus::SKIP_AUTOMATION_OFF;
        }

        if (! $business->whatsapp_enabled) {
            return WhatsAppStatus::SKIP_AUTOMATION_OFF;
        }

        /*
         * والباقة تُسأل قبل الإرسال لا بعده.
         *
         * القدرة تُغلق الشاشة، والشاشةُ ليست البابَ الوحيد: الإشعار يُرسل من
         * الطلب حين تتغيّر حالته، لا من زرٍّ يضغطه أحد. فقفلٌ في الشاشة وحدها
         * يعني رسائل تُرسَل — وتُحاسَب على المنصّة — لباقةٍ لا تشملها.
         *
         * والسبب يُكتب في السجلّ باسمه: «امتنع لأنّ الباقة لا تشمله» يُقرأ
         * ويُرقّى، و«الأتمتة مطفأة» يُبحث عن مفتاحٍ لا وجود له.
         */
        if (! PlanFeatures::allows($business, 'whatsapp')) {
            return WhatsAppStatus::SKIP_PLAN;
        }

        $mode = self::effectiveMode($business);

        if ($mode === WhatsAppMode::BUSINESS_OWN && ! self::canUseOwnNumber($business)) {
            return WhatsAppStatus::SKIP_OWN_NOT_ALLOWED;
        }

        if ($mode === WhatsAppMode::ABAAD_SHARED && ! self::sharedEnabled()) {
            return WhatsAppStatus::SKIP_AUTOMATION_OFF;
        }

        return null;
    }
}
