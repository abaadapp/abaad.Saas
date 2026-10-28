<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Setting;

/**
 * تجربةُ التسجيل الذاتيّ: الأساسيّةُ بلا نقطة البيع، أيًّا كانت سياسةُ التسعير.
 *
 * ═══ العطب ═══
 *
 * سياسةُ الإضافات (`Addons\PricingPolicy`) تفصل نقطةَ البيع عن الأساسيّة، لكنّها
 * تُختم بمفتاح الإطلاق. فيومَ يكون المفتاحُ مطفأً يُنشأ المسجِّلُ بنفسه على السياسة
 * السابقة، وفيها نقطةُ البيع مفتوحةٌ لكلّ باقة: زائرٌ يجرّب الأساسيّة مجّانًا فيبيع من
 * الصندوق مجّانًا، وهو ما لا تبيعه الأساسيّة.
 *
 * ═══ الختمُ يومَ التسجيل لا قاعدةٌ على الماضي ═══
 *
 * `Signup` يكتب هذا المفتاح لكلّ متجرٍ يسجّل بنفسه. ومن سجّل قبله يبقى كما وُعد
 * يومَ سجّل: لا صفَّ له، فلا يُغلق عليه شيءٌ في منتصف تجربته. ولا تاريخ يُقارَن:
 * يومُ النشر يتبدّل، والصفُّ لا يتبعه شيء.
 *
 * ═══ وينتهي بالسداد ═══
 *
 * الإغلاقُ ما بقيت التجربةُ غيرَ مدفوعة (`Tenancy::isUnpaidSelfSignup`). فإذا فتح
 * فريقُ أبعاد الاشتراكَ وسُدّدت فاتورتُه، قرّرت السياسةُ والباقةُ كما تقرّران لغيره.
 * ومنحةٌ ساريةٌ من فريق أبعاد تفتح نقطة البيع في التجربة (`PlanFeatures::allows`).
 */
final class TrialOffer
{
    public const WITHOUT_POS = 'trial_without_pos';

    public static function stamp(Business $business): void
    {
        Setting::updateOrCreate(
            ['business_id' => $business->id, 'key' => self::WITHOUT_POS],
            ['value' => '1'],
        );
    }

    /** هل تُغلق تجربةُ هذا المتجر نقطةَ البيع الآن؟ والأرخصُ يُسأل أوّلًا */
    public static function closesPos(?Business $business): bool
    {
        return $business !== null && $business->exists
            && $business->registration_source === Business::SOURCE_SELF_SIGNUP
            && Setting::where('business_id', $business->id)->where('key', self::WITHOUT_POS)->value('value') === '1'
            && Tenancy::isUnpaidSelfSignup($business);
    }
}
