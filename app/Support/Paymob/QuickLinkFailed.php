<?php

namespace App\Support\Paymob;

use RuntimeException;

/**
 * فشلُ طلبٍ إلى Paymob — ورسالتُه تُقرأ للتاجر ولا سرَّ فيها.
 *
 * و`uncertain`: الطلبُ بلغ Paymob ولم يرجع جوابُه مقروءًا — انقطع الاتصالُ
 * بعد الإرسال، أو ردّت بخطأ خادمٍ أو بما لا يُقرأ. فقد يكون الرابطُ أُنشئ
 * هناك ولم يصلنا. ورفضٌ صريح (مفتاحٌ مرفوض، تكاملٌ لا يطابق) ليس منه:
 * Paymob قالت «لا» فلا رابطَ هناك.
 */
final class QuickLinkFailed extends RuntimeException
{
    public function __construct(string $message = '', int $code = 0, public readonly bool $uncertain = false)
    {
        parent::__construct($message, $code);
    }
}
