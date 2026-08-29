<?php

namespace App\Support;

/**
 * من أيّ رقمٍ تخرج الرسالة — قيمتان لا ثالث لهما.
 *
 * `abaad_shared`: رقم المنصّة، وصلةٌ واحدة تخدم كلّ المتاجر، ولكلّ متجرٍ
 * حصّتُه الشهرية منه.
 *
 * `business_own`: رقم المحلّ نفسه، ميزةٌ يمنحها مدير المنصّة، ولا تستهلك
 * حصّة المشترك لأنّ الإرسال على حساب المحلّ لا على حسابنا.
 *
 * ومكتوبةٌ هنا لا في كلّ موضع: نصٌّ يُكرَّر في عشرة ملفّات يسقط منه حرفٌ في
 * أحدها فلا يُطابق شيئًا — ولا يشكو أحد، تُقارَن القيمة فتُخطئ بصمت.
 */
class WhatsAppMode
{
    public const ABAAD_SHARED = 'abaad_shared';

    public const BUSINESS_OWN = 'business_own';

    public const ALL = [self::ABAAD_SHARED, self::BUSINESS_OWN];

    /** صاحب الوصلة: المنصّة أم متجر */
    public const OWNER_PLATFORM = 'platform';

    public const OWNER_BUSINESS = 'business';

    public const OWNERS = [self::OWNER_PLATFORM, self::OWNER_BUSINESS];

    /** الوضع الافتراضي لكلّ متجرٍ جديد */
    public const DEFAULT = self::ABAAD_SHARED;

    public static function label(string $mode): string
    {
        return match ($mode) {
            self::BUSINESS_OWN => __('رقم المتجر'),
            default => __('رقم أبعاد'),
        };
    }

    /** صاحب الوصلة الذي يخدم هذا الوضع */
    public static function ownerFor(string $mode): string
    {
        return $mode === self::BUSINESS_OWN ? self::OWNER_BUSINESS : self::OWNER_PLATFORM;
    }
}
