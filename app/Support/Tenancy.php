<?php

namespace App\Support;

use App\Models\Business;
use App\Models\User;

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

        if (self::expired($business)) {
            return self::SUBSCRIPTION_EXPIRED;
        }

        return null;
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
            self::BUSINESS_DISABLED => __('حساب المتجر معطَّل. تواصل مع الدعم.'),
            self::SUBSCRIPTION_EXPIRED => __('انتهى اشتراك المتجر. جدّده لمتابعة العمل.'),
            default => __('لا يمكن الدخول الآن.'),
        };
    }
}
