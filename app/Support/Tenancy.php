<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * حالة المستأجر: هل يُسمح لهذا الحساب بالعمل الآن؟
 *
 * كانت «موقوف» و«معطل» و«منتهي» كلماتٍ تُكتب في أعمدة لا يقرؤها أحد: موظفٌ
 * موقوفٌ في شركةٍ معطّلة انتهى اشتراكها منذ ستة أشهر كان يسجّل الدخول ويبيع.
 * فوسيلةُ المنصّة الوحيدة على عميلٍ لا يدفع لم تكن تفعل شيئًا.
 *
 * مصدرٌ واحد يقرأه حارس الطلب وشاشة الدخول معًا — لئلا يُمنع عند الباب من
 * يمرّ من نافذة.
 */
class Tenancy
{
    public const USER_SUSPENDED = 'user_suspended';

    public const USER_REMOVED = 'user_removed';

    public const BUSINESS_DISABLED = 'business_disabled';

    public const SUBSCRIPTION_EXPIRED = 'subscription_expired';

    /** حالات الحساب التي تعني «موقوف» — الواجهة تكتبها بأكثر من لفظ */
    private const BLOCKED_USER = ['موقوف', 'معطل', 'معطّل'];

    private const BLOCKED_BUSINESS = ['معطل', 'معطّل'];

    /**
     * سبب المنع إن وُجد، وإلا null.
     *
     * مدير المنصة لا مستأجر له، فلا يُفحص: هو من يوقف لا من يُوقَف.
     */
    public static function blockReason(?User $user): ?string
    {
        if ($user === null || $user->isSuperAdmin()) {
            return null;
        }

        /*
         * المحذوف قبل الموقوف: الحذف أشدّ، ورسالتُه غير رسالته.
         *
         * الحذف هنا ناعم — يبقى الصفّ ويُرفع عنه العلم — ومزوّد المصادقة
         * يقرأ المستخدم بلا نطاقات، فالمحذوف يبقى مسجَّلًا في جلسته. فصاحب
         * النشاط يطرد موظفًا ويحذفه من «الموظفون»، والموظف واقفٌ في الشارع
         * ولوحتُه مفتوحة على هاتفه: يقرأ الزبائن، ويعدّل المنتجات، ويكتب —
         * أثبتناه: صفٌّ كُتب بعد الحذف. والنافذة ١٢٠ دقيقة تتجدّد مع كل نقرة،
         * أي ما دام يضغط.
         *
         * ودخولُه من جديد ممنوعٌ أصلًا: البحث بالبريد وبالرمز يمرّ بنطاق
         * الحذف الناعم فلا يجده. فالثغرة في الجلسة القائمة وحدها — وهنا
         * تُغلق، عند النقطة التي يقرؤها كلّ باب.
         */
        if (method_exists($user, 'trashed') && $user->trashed()) {
            return self::USER_REMOVED;
        }

        if (in_array((string) $user->status, self::BLOCKED_USER, true)) {
            return self::USER_SUSPENDED;
        }

        $business = $user->business;
        if (! $business) {
            return null;
        }

        if (in_array((string) $business->status, self::BLOCKED_BUSINESS, true)) {
            return self::BUSINESS_DISABLED;
        }

        // بعد المهلة لا عندها: المهلة موجودة كي لا يقف متجرٌ لتأخّر حوالةٍ يومًا
        if (self::locked($business)) {
            return self::SUBSCRIPTION_EXPIRED;
        }

        return null;
    }

    /**
     * منعٌ يُخرج من الباب، أم حجزٌ داخل الصفحة؟
     *
     * الموقوف والمعطَّل تُنهى جلستهما ويُردّان إلى شاشة الدخول: أمرهما بيد
     * غيرهما ولا شيء يفعلانه في النظام. ومنتهي الاشتراك يدخل ويقف عند صفحةٍ
     * واحدة تقول له كم عليه وبمن يتّصل — لأن طردَه عند الباب يجعله يتّصل
     * ليسأل «لماذا لا أدخل؟» قبل أن يسأل «كيف أجدّد؟».
     */
    public static function isHard(string $reason): bool
    {
        return $reason !== self::SUBSCRIPTION_EXPIRED;
    }

    /**
     * مهلة السماح بالأيّام — من إعدادات المنصة.
     *
     * المفتاح `grace_days` كان في شاشة إعدادات المنصة منذ البداية ولا يقرؤه
     * أحد: مقبضٌ يديره المشغّل ولا يوصَّل بشيء، وهو أسوأ من غيابه لأنه
     * يُطمئن. وصفرٌ يعني الإقفال لحظة الانتهاء — وهو خيارٌ مشروع.
     */
    public static function graceDays(): int
    {
        return max(0, (int) self::platform('grace_days', 7));
    }

    /**
     * تجربةٌ ذاتيّةٌ مجّانيّة لم تُدفع؟ — أربعةَ عشر يومًا، لا يومَ بعدها.
     *
     * من فتح متجره بنفسه من `/register` (متجرٌ حقيقيّ لا تجريبيّ) ولم
     * يُسدَّد له اشتراكٌ قطّ (`Billing::hasPaidSubscription`). مدّتُه
     * `Signup::TRIAL_DAYS` بحرفها: لا مهلةَ بعدها (`graceDaysFor`)، ولا يُبقيه
     * إطفاءُ الإقفال التلقائيّ يعمل (`locked`).
     *
     * وما سواه كما كان: اليدويّ والقديم (`registration_source` فارغ)
     * والتجريبيّ، ومن سجّل بنفسه ثمّ دفع اشتراكًا — هذا عميلٌ يدفع، ومهلتُه
     * وإقفالُه مهلةُ العملاء وإقفالُهم.
     */
    public static function isUnpaidSelfSignup(?Business $business): bool
    {
        return $business !== null
            && $business->registration_source === Business::SOURCE_SELF_SIGNUP
            && ! $business->is_demo
            && ! Billing::hasPaidSubscription($business);
    }

    /**
     * مهلةُ هذا المتجر بعينه — العامّةُ، إلّا لتجربةٍ ذاتيّةٍ لم تُدفع.
     *
     * المهلةُ وُضعت لعميلٍ يدفع كي لا يقف صندوقُه لتأخّر حوالة. والتجربةُ
     * الذاتيّة غيرُ المدفوعة لم يدفع صاحبُها شيئًا تتأخّر حوالتُه: بالمهلة
     * العامّة صارت أربعةُ عشر يومًا واحدًا وعشرين. فلا مهلةَ لها — وتُقفل
     * حيث تقول `expired`.
     */
    public static function graceDaysFor(?Business $business): int
    {
        return self::isUnpaidSelfSignup($business) ? 0 : self::graceDays();
    }

    /** إعدادٌ من إعدادات المنصة (business_id = null) */
    public static function platform(string $key, $default = null)
    {
        $value = Setting::whereNull('business_id')->where('key', $key)->value('value');

        return $value === null || $value === '' ? $default : $value;
    }

    /**
     * وضع الصيانة: النظام موقوفٌ على التجّار دون مدير المنصة.
     *
     * المفتاح كان معروضًا في إعدادات المنصة ولا يقرؤه شيء — يُشغّله المشغّل
     * قبل ترقيةٍ ويظنّ أنه أغلق الأبواب، والتجّار يبيعون على قاعدةٍ تُهاجَر
     * تحتهم. مقبضُ أمانٍ لا يُمسك أخطرُ من غيابه.
     */
    public static function maintenance(): bool
    {
        return (string) self::platform('maintenance_mode', '0') === '1';
    }

    /** آخر لحظةٍ يعمل فيها المتجر — نهاية الاشتراك زائدَ مهلتِه (`graceDaysFor`) */
    public static function locksAt(?Business $business): ?Carbon
    {
        return $business?->ends_at?->endOfDay()->addDays(self::graceDaysFor($business));
    }

    /**
     * أُقفل فعلًا؟ — وهو غير «انتهى».
     *
     * الفرق ليس لفظيًّا: بينهما أيّامٌ يعمل فيها المتجر كاملًا ويرى صاحبه
     * شريطًا أحمر يعدّ ما بقي. وخلطُهما يعني أن تأخّر حوالةٍ ساعةً يوقف
     * صندوقًا في يوم عيد.
     */
    public static function locked(?Business $business): bool
    {
        /*
         * ما لم ينتهِ لا يُقفل — ولا يُسأل عن شيءٍ بعده.
         *
         * المهلةُ لا تقلّ عن صفر، فـ`locksAt` لا يسبق نهايةَ يوم الانتهاء: هذا
         * لا يغيّر جوابًا. وإنّما يُغني متجرًا في اشتراكه عن سؤال الفواتير
         * (`isUnpaidSelfSignup`) مع كلّ طلب.
         */
        if (! self::expired($business)) {
            return false;
        }

        /*
         * والتجربةُ الذاتيّة غيرُ المدفوعة تقف حين تنتهي — ولو أُطفئ الإقفال.
         *
         * إطفاءُ `auto_suspend` قرارُ المشغّل أن يتابع عملاءه بيده قبل أن
         * يقف صندوقُ أحدهم. ومن لم يدفع شيئًا ليس عميلًا يُتابَع: تجربتُه أربعةَ
         * عشر يومًا بحرفها. ومن دفع بعدها خرج من هذا وتبع المقبضَ كغيره.
         */
        if (self::isUnpaidSelfSignup($business)) {
            return true;
        }

        /*
         * الإقفال التلقائي مقبضٌ للمشغّل.
         *
         * كان «تعطيل الحساب تلقائيًا عند انتهاء المهلة» مربّعًا في إعدادات
         * المنصة لا يقرؤه شيء — والنظام يقفل دائمًا. وإطفاؤه يعني متابعةً
         * يدوية: تنبيهاتٌ تُرسل ومتاجر تعمل حتى يُقفلها المشغّل بنفسه.
         */
        if ((string) self::platform('auto_suspend', '1') === '0') {
            return false;
        }

        return self::locksAt($business)?->isPast() ?? false;
    }

    /** كم يومًا بقي من المهلة بعد الانتهاء؟ null إن لم ينتهِ بعد */
    public static function graceLeft(?Business $business): ?int
    {
        if (! self::expired($business)) {
            return null;
        }

        return max(0, (int) now()->startOfDay()->diffInDays(self::locksAt($business), false));
    }

    /**
     * انتهى الاشتراك؟
     *
     * يوم الانتهاء نفسه يبقى مسموحًا: من دفع حتى ٣١ يناير يعمل يوم ٣١ كاملًا.
     * ولا تاريخ = لا مدّة محدَّدة، فلا يُمنع — الحسابات القديمة قبل الاشتراكات
     * لا يجوز أن تُقفل لأن حقلًا فيها فارغ.
     */
    public static function expired(?Business $business): bool
    {
        return $business?->ends_at !== null && $business->ends_at->endOfDay()->isPast();
    }

    /** كم يومًا بقي؟ null إن لا تاريخ له */
    public static function daysLeft(?Business $business): ?int
    {
        if (! $business?->ends_at) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($business->ends_at->startOfDay(), false);
    }

    /** الرسالة كما تُقال للمستخدم — لا رمز الحالة */
    public static function message(string $reason): string
    {
        return match ($reason) {
            self::USER_SUSPENDED => __('حسابك موقوف. راجع صاحب النشاط.'),
            self::USER_REMOVED => __('لم يعد هذا الحساب موجودًا. راجع صاحب النشاط.'),
            self::BUSINESS_DISABLED => __('حساب المتجر معطَّل. تواصل مع الدعم.'),
            self::SUBSCRIPTION_EXPIRED => __('انتهى اشتراك المتجر. جدّده لمتابعة العمل.'),
            default => __('لا يمكن الدخول الآن.'),
        };
    }
}
