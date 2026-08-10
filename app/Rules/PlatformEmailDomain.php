<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * بريد مدير المنصة يكون على نطاق الشركة وحده.
 *
 * هويّة من يملك مفتاح المنصّة كلّها لا تُعلَّق على بريدٍ شخصيّ عند مزوّدٍ خارجي:
 * حسابٌ يُسترجَع برقم هاتفٍ قديم أو يُغلقه المزوّد لسببٍ لا تعرفه، ويُفتح به
 * كلُّ متجرٍ على المنصّة. والنطاق ملكُ الشركة: إن ترك الموظفُ عمله أُغلق بريده،
 * وهذا ما لا يمكن فعله بحسابٍ على gmail.
 *
 * والحدّ عند الكتابة لا عند الدخول عمدًا. منعُ الدخول يُغلق اللوحة في وجه
 * صاحبها لحظة تطبيق القاعدة — والدخول يحرسه كلمةُ المرور لا شكلُ العنوان.
 * ومن خالف القاعدة قبل وجودها يبلّغ عنه preflight ولا يُطرد.
 */
class PlatformEmailDomain implements ValidationRule
{
    public const DOMAIN = 'abaadapp.om';

    /** هل هذا العنوان صالح لمدير منصة؟ */
    public static function matches(?string $email): bool
    {
        return is_string($email)
            && str_ends_with(mb_strtolower(trim($email)), '@'.self::DOMAIN);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::matches(is_string($value) ? $value : null)) {
            $fail(__('بريد مدير المنصة يكون على نطاق :domain وحده — مثال: admin@:domain', [
                'domain' => self::DOMAIN,
            ]));
        }
    }
}
