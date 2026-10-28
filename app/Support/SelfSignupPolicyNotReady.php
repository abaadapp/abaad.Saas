<?php

namespace App\Support;

use RuntimeException;

/**
 * سياسةُ الإضافات ليست ما سيُختم — فلا يُفتح متجرٌ بالتسجيل الذاتيّ.
 *
 * الباقاتُ المعلنة (abaadapp.om) أساسيّةٌ بلا نقطة البيع، وهذا عقدُ `addons_v1`.
 * ومتجرٌ يُختم على السابقة يناقضها: نقطةُ البيع فيها مشمولةٌ في كلّ باقة، فتُفتح
 * له مجّانًا يومَ يُسدّد أوّلَ فاتورة. فيُردّ التسجيلُ ولا يُكتب شيء — حتى يُفتح
 * الإطلاقُ وتكتمل شروطُه (`Addons\PricingPolicy::forNewMerchant`).
 *
 * تُرمى من `Signup::register` قبل المعاملة، وثانيةً داخلها إن خُتم المتجرُ على
 * غيرها (إطلاقٌ أُطفئ بين السؤال والكتابة) فتُلغى المعاملةُ كلُّها.
 */
final class SelfSignupPolicyNotReady extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Self-signup needs the addons_v1 policy: the launch switch is off or its conditions are incomplete.');
    }
}
