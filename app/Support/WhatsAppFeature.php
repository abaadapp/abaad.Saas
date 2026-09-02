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
     * خطواتُ الربط والتفعيل — بترتيبها، وحالُ كلِّ خطوة.
     *
     * وهذا أوّلُ ما يُعرض في شاشة الإشعارات لأنّه شرطُ كلِّ ما بعده: مقابضُ
     * «متى تُرسل الرسالة» لا تُرسل حرفًا قبل أن تكتمل هذه الخطوات، ومن رآها
     * أوّلًا ظنّ أنّ إطفاءها وإشعالها هو الإعداد كلُّه.
     *
     * والحالُ يُشتقّ من الدوالّ التي يسألها المُرسِل نفسه — `blockReason`
     * و`WhatsAppConnections::resolve` — لا من فحصٍ ثانٍ يُكتب هنا. ولو كُتب
     * ثانٍ لَافترقا يومًا: تقول الشاشة «جاهز» ويمتنع المُرسِل، فينتظر التاجر
     * رسائل لا تخرج ولا شيء يقول له لماذا.
     *
     * @return array{ready:bool, steps:list<array{key:string, label:string, done:bool, detail:?string, fix:?string, theirs:bool}>}
     */
    public static function readiness(Business $business): array
    {
        $mode = self::effectiveMode($business);
        $own = $mode === WhatsAppMode::BUSINESS_OWN;

        $connected = WhatsAppConnections::resolve($business) !== null;

        $steps = [
            self::step(
                'platform',
                'واتساب مفعَّل في المنصّة',
                self::globallyEnabled(),
                fix: 'هذا إعدادُ أبعاد لا إعدادُك — راجعنا لتفعيله.',
                theirs: true,
            ),
            self::step(
                'account',
                'واتساب مفعَّل لحسابك',
                (bool) $business->whatsapp_enabled,
                fix: 'التفعيل يفتحه أبعاد لحسابك — راجعنا.',
                theirs: true,
            ),
            self::step(
                'plan',
                'باقتك تشمل إشعارات واتساب',
                PlanFeatures::allows($business, 'whatsapp'),
                fix: PlanFeatures::refusal($business, 'whatsapp'),
                theirs: true,
            ),
            self::step(
                $own ? 'own' : 'shared',
                $own ? 'رقم متجرك مربوطٌ ويعمل' : 'الرقم المشترك جاهز',
                $connected,
                detail: $own ? null : __('تخرج الرسائل من رقم أبعاد المعتمَد.'),
                /*
                 * وربطُ الرقم وحده هو ما بيد التاجر من هذه الخطوات.
                 *
                 * والفرق يُقال: خطوةٌ ينتظر فيها أبعاد لا يُطلب منه إصلاحها،
                 * وخطوةٌ بيده تُقال له بصيغة الأمر. ولو خُلطتا لَبقي ينتظر
                 * ما عليه أن يفعله، أو حاول ما لا يملكه.
                 */
                fix: $own
                    ? 'اربط رقم متجرك أدناه — أو بدّل الإرسال إلى رقم أبعاد.'
                    : 'الرقم المشترك غير متاح الآن — راجع أبعاد.',
                theirs: ! $own,
            ),
        ];

        return [
            'ready' => self::blockReason($business) === null && $connected,
            'steps' => $steps,
        ];
    }

    private static function step(
        string $key,
        string $label,
        bool $done,
        ?string $detail = null,
        ?string $fix = null,
        bool $theirs = false,
    ): array {
        return [
            'key' => $key,
            'label' => __($label),
            'done' => $done,
            'detail' => $done ? $detail : null,
            // وما تمّ لا يُقال كيف يُصلَح — نصيحةٌ تحت خطوةٍ مكتملة ضجيج
            'fix' => $done ? null : ($fix === null ? null : __($fix)),
            // خطوةٌ ينتظر فيها أبعاد، لا خطوةٌ يفعلها بيده
            'theirs' => $theirs,
        ];
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
