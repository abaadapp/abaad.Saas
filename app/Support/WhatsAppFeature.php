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
        $ownAllowed = self::canUseOwnNumber($business);

        /*
         * والإذنُ يدخل في حال الخطوة، لا الوصلةُ وحدها.
         *
         * متجرٌ ربط رقمه ثمّ سُحبت منه الميزة تبقى وصلتُه صالحة ويبقى وضعُه
         * `business_own` — لا يُدفع إلى الرقم المشترك بصمت. فلو قيست الخطوة
         * بالوصلة وحدها لَبدت تامّة، ولَقرأ التاجر أربع علاماتٍ خضراء تحت
         * رأسٍ يقول «غير جاهز» ولا سببَ يُقرأ — وهو العطب نفسه الذي بُنيت
         * هذه الشاشة لإزالته، في صورةٍ أخرى.
         */
        $connected = WhatsAppConnections::resolve($business) !== null
            && ($ownAllowed || ! $own);

        $steps = [
            Integration::step(
                'platform',
                'واتساب مفعَّل في المنصّة',
                self::globallyEnabled(),
                fix: 'هذا إعدادُ أبعاد لا إعدادُك — راجعنا لتفعيله.',
                theirs: true,
            ),
            Integration::step(
                'account',
                'واتساب مفعَّل لحسابك',
                (bool) $business->whatsapp_enabled,
                fix: 'التفعيل يفتحه أبعاد لحسابك — راجعنا.',
                theirs: true,
            ),
            Integration::step(
                'plan',
                'باقتك تشمل إشعارات واتساب',
                PlanFeatures::allows($business, 'whatsapp'),
                fix: PlanFeatures::refusal($business, 'whatsapp'),
                theirs: true,
            ),
            Integration::step(
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
                fix: match (true) {
                    // سُحبت الميزة وهو عليها: يُقال ما جرى، ويُدَلّ على المخرج
                    $own && ! $ownAllowed => 'ميزةُ رقم المتجر سُحبت من حسابك — بدّل الإرسال إلى رقم أبعاد أدناه، أو راجعنا لإعادتها.',
                    $own => 'اربط رقم متجرك أدناه — أو بدّل الإرسال إلى رقم أبعاد.',
                    default => 'الرقم المشترك غير متاح الآن — راجع أبعاد.',
                },
                theirs: ! $own,
            ),
        ];

        /*
         * ═══ وخطوةُ القالب — آخرُ حلقةٍ قبل أن تخرج الرسالة ═══
         *
         * ميتا لا تقبل نصًّا حرًّا في رسالةٍ يبدؤها العمل: قالبًا معتمَدًا
         * باسمه. و`enabled` في جدولنا مقبضُنا نحن لا اعتمادُها هي.
         *
         * فكان التاجر يقرأ أربعَ علاماتٍ خضراء و«جاهز» فوقها، وكلُّ قوالب
         * أبعاد الستّة `PENDING` عند ميتا — أي أنّ أوّلَ طلبٍ يُؤكَّد تُردّ
         * رسالتُه. والخطوةُ الخامسة القديمة («رسائلك تصل») لا تظهر إلّا
         * **بعد** أن يُحاول الإرسال ويفشل: فهي تكشف العطب بعد أن يقع
         * بزبونٍ حقيقيّ، وهذه تكشفه قبله.
         *
         * وثلاثُ حالاتٍ لا اثنتان: معتمَدةٌ كلُّها، أو فيها ما ليس معتمَدًا،
         * أو **لم تُسأل ميتا بعد**. والثالثةُ تُقال كما هي: «لا نعرف» غير
         * «لا» — وادّعاءُ أحدهما مكانَ الآخر هو العطبُ نفسُه في صورةٍ أخرى.
         */
        if ($connected) {
            $approved = WhatsAppTemplates::approvalState(
                $own ? WhatsAppMode::OWNER_BUSINESS : WhatsAppMode::OWNER_PLATFORM,
                $own ? $business->id : null,
            );

            $steps[] = Integration::step(
                'templates',
                'قوالب رسائلك معتمَدةٌ لدى واتساب',
                $approved === true,
                detail: $approved === true ? __('تقبلها واتساب، فتخرج الرسالة.') : null,
                fix: match ($approved) {
                    false => $own
                        ? __('واتساب لم يعتمد قوالبك بعد — تُراجَع من حساب أعمالك، ولا تخرج رسالةٌ قبل اعتمادها.')
                        : __('قوالب أبعاد قيد المراجعة لدى واتساب — لا تخرج رسالةٌ قبل اعتمادها، ونحن نتابعها.'),
                    default => __('لم نقرأ حال القوالب من واتساب بعد — تُقرأ تلقائيًّا كلّ ساعة.'),
                },
                /* واعتمادُ قوالب أبعاد شأنُنا؛ وقوالبُ من ربط رقمه شأنُه */
                theirs: ! $own,
            );
        }

        /*
         * ═══ والخطوةُ التالية تقرأ نتيجةً لا إعدادًا ═══
         *
         * الأربعُ فوقها تقول «مربوط». وهذه وحدها تقول «يصل».
         *
         * وحين حجبت Meta التطبيق بقيت الأربعُ خضراء و«جاهز» فوقها، وكلُّ
         * رسالةٍ تُردّ وتُكتب `failed` في جدولٍ لا تفتحه شاشة. فيبيع التاجر
         * ولا يصل زبونَه شيء، وتطمئنه لوحتُه — ولا يعرف حتّى يسأل.
         *
         * ولا تُضاف إن لم تُجرَّب: متجرٌ لم يُرسل شيئًا بعد لا يُقال له «لا
         * تصل رسائلك» — لم تُرسَل رسالةٌ لتصل.
         */
        $health = WhatsAppHealth::recent($business->id);

        if ($health['attempts'] > 0) {
            $alert = WhatsAppHealth::alert($business->id);

            $steps[] = Integration::step(
                'delivery',
                'رسائلك تصل زبائنك',
                $alert === null,
                detail: $alert === null
                    ? __('وصلت :n من :t في آخر يوم.', ['n' => $health['sent'], 't' => $health['attempts']])
                    : null,
                fix: $alert['text'] ?? null,
                /* والعطبُ يُنسب إلى صاحبه: تطبيقٌ محجوبٌ ليس خطأ التاجر */
                theirs: (bool) ($alert['ours'] ?? false),
            );
        }

        return [
            /*
             * و«بدأ» غير «تمّ».
             *
             * البوّابة تُقاس بالأوّل: تاجرٌ جديد لم يُفتح له واتساب بعدُ ولا
             * ربط رقمًا لم يبدأ شيئًا — فيُعرض له بابٌ وزرّ، لا قائمةُ
             * مراحلَ لم يطلبها ولا مقابضُ أحداثٍ لا تُرسل حرفًا.
             *
             * والتفعيلُ لحسابه علامةُ البدء لا الوصلةُ المشتركة: تلك مربوطةٌ
             * للمنصّة كلِّها، فلو قيست بها لَما رأى الباب أحد.
             */
            'connected' => MarketingSettings::group($business->id, 'connect')['wa_setup_started'] === '1'
                || self::blockReason($business) === null && $connected
                || WhatsAppConnections::forBusiness($business->id) !== null,
            /*
             * و«جاهز» تُشتقّ من الخطوات نفسِها — لا تُكتب بجانبها.
             *
             * كانت تقرأ الربط وحده، فتُكتب «تمّ الربط — رسائلك تخرج» فوق
             * أربع علاماتٍ خضراء بينما لا يخرج شيء. فأُضيف إليها الوصول.
             *
             * ═══ ثمّ افترق المقياسان ═══
             *
             * ويومَ أُضيفت خطوةُ «قوالبك معتمَدة» ظهرت حمراءَ في القائمة
             * و«جاهز» خضراءُ فوقها — لأنّ هذا السطر يعدّ شروطًا مكتوبةً بيده
             * والقائمةُ تعدّ خطواتٍ أخرى. وفحصان لسؤالٍ واحد يفترقان يوم
             * يُبدَّل أحدهما.
             *
             * فصارت تُقرأ من `$steps`: خطوةٌ تُضاف غدًا تدخل الحسابَ وحدَها،
             * ولا يُقال «جاهز» فوق علامةٍ حمراء أبدًا.
             *
             * و`blockReason` تبقى معها لأنّها تحرس ما لا خطوةَ له: إطفاءُ
             * الرقم المشترك في المنصّة يمنع الإرسال ولا يُرسم سطرًا هنا.
             */
            'ready' => self::blockReason($business) === null
                && collect($steps)->every(fn ($step) => $step['done'] === true),
            'steps' => $steps,
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
