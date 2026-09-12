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

    /*
     * ولمَ يخدم الرقم — و«لمن» غيرُ «لماذا».
     *
     * `owner_type` يقول من يملك الرقم. وهذا يقول ما يخرج منه ويدخل إليه:
     * رقمٌ يُرسل إشعاراتِ الطلبات نيابةً عن المحلّات، ورقمٌ يستقبل من يريد
     * أن يشتري أبعاد. والشرطان لا يجتمعان على رقم — انظر الترحيل
     * `the_sales_number_is_not_the_notice_number`.
     */

    /** رقمُ الإشعارات — يُرسل نيابةً عن المحلّات، ويستقبل دعمَ أصحابها */
    public const PURPOSE_NOTIFICATIONS = 'notifications';

    /** رقمُ مبيعات أبعاد — يستقبل من يريد أن يشتريها، ولا يُرسل إشعارَ طلبٍ قطّ */
    public const PURPOSE_CRM_SALES = 'crm_sales';

    public const PURPOSES = [self::PURPOSE_NOTIFICATIONS, self::PURPOSE_CRM_SALES];

    public static function purposeLabel(string $purpose): string
    {
        return match ($purpose) {
            self::PURPOSE_CRM_SALES => __('مبيعات أبعاد'),
            default => __('إشعارات الطلبات'),
        };
    }

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
