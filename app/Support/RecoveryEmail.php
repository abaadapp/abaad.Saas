<?php

namespace App\Support;

use App\Models\User;

/**
 * بريد الاستعادة — عنوانٌ يُثبَت أنّ صاحبه يقرؤه.
 *
 * سبعةٌ من كلّ عشرة تجّار يدخلون ببريدٍ على `@abaadapp.om`: عنوانُ دخولٍ لا
 * صندوق خلفه. وما كان يُسلَّم إليه الرابط هو `businesses.email` — حقلٌ كتبه
 * موظّف المنصّة ولم يفتحه صاحبه قطّ.
 *
 * والفرق بين «مكتوب» و«مختوم» هو الأمان كلّه هنا: المكتوب يُعرض ويُصحَّح،
 * والمختوم وحده يُرسَل إليه رمزُ استعادة.
 */
class RecoveryEmail
{
    /**
     * تسوية العنوان — أحرفٌ صغيرة بلا فراغات.
     *
     * ولا تُمسّ نقاطُ جيميل ولا ما بعد «+»: من كتب `a.b+shop@gmail.com` يقصده،
     * وتطبيعُه إلى `ab@gmail.com` يجعل عنوانين مختلفين في نظرنا واحدًا —
     * فيصير من يملك أحدهما مالكًا لحساب الآخر.
     */
    public static function normalize(?string $raw): ?string
    {
        $email = mb_strtolower(trim((string) $raw));

        return $email === '' ? null : $email;
    }

    /**
     * العنوان الداخليّ ليس صندوقًا.
     *
     * `@abaadapp.om` معرّفُ دخولٍ اخترعناه نحن، ولا بريد خلفه. وقبولُه بريدَ
     * استعادةٍ يعني حسابًا يُرسَل رمزُه إلى العدم — ثمّ تاجرٌ ينتظر رسالةً
     * لا تُرسل ولا يعرف أحدٌ لماذا.
     */
    public static function isInternal(?string $email): bool
    {
        return $email !== null && str_ends_with(mb_strtolower($email), MerchantAccount::DOMAIN);
    }

    /**
     * قواعد الحقل.
     *
     * ولا تفرّد عالميّ: صاحبُ محلّين يستعمل بريده نفسه لهما، ومنعُه يدفعه
     * إلى بريدٍ ثانٍ لا يقرؤه. والأمان من هويّة الحساب لا من ندرة العنوان —
     * فمن يملك الصندوق لا يستطيع به شيئًا حتى يجتاز حارس الحساب نفسه.
     */
    public static function rules(): array
    {
        return ['required', 'string', 'email:rfc', 'max:150'];
    }

    public static function messages(): array
    {
        return [
            'recovery_email.email' => __('اكتب بريدًا صالحًا.'),
            'recovery_email.required' => __('اكتب بريد الاستعادة.'),
        ];
    }

    /** هل لهذا الحساب بريدٌ مختوم يُرسَل إليه؟ */
    public static function verifiedFor(?User $user): ?string
    {
        if (! $user || blank($user->recovery_email) || $user->recovery_email_verified_at === null) {
            return null;
        }

        return self::isInternal($user->recovery_email) ? null : $user->recovery_email;
    }

    /**
     * العنوان مُقنَّعًا — `mo***@gmail.com`.
     *
     * يُعرض ليطمئنّ صاحبه أنّ الرمز ذهب إلى صندوقه هو، ولا يُعرض كاملًا:
     * شاشةٌ عامّة تكتب عنوان بريد صاحب الحساب تُسلّم نصف الطريق لمن يجرّب
     * أسماء الدخول.
     */
    public static function mask(?string $email): ?string
    {
        if (blank($email) || ! str_contains($email, '@')) {
            return null;
        }

        [$name, $domain] = explode('@', $email, 2);
        $keep = mb_substr($name, 0, min(2, mb_strlen($name)));

        return $keep.str_repeat('*', 3).'@'.$domain;
    }
}
