<?php

namespace App\Support;

use App\Models\Business;
use App\Models\JobTitle;
use App\Models\User;

/**
 * حساب دخول التاجر — يُنشأ مع الشركة لا بعدها.
 *
 * كانت الشركة تُسجَّل في لوحة المنصة ولا يُنشأ لها حساب: سجلٌّ في الجدول لا
 * يفتحه أحد. فلا التاجر يدخل، ولا الدعم يستطيع «الدخول كتاجر» (لا مستخدم
 * ينتحله)، ولا يظهر العطب إلا حين يتصل صاحبها يسأل عن كلمة مروره.
 *
 * والبريد على نطاق واحد لكل التجّار: يُكتب الاسم وحده ويُلحق به النطاق، فلا
 * يخطئ أحدٌ في كتابته ولا تفترق العناوين على أشكال.
 */
class MerchantAccount
{
    /** نطاق حسابات التجّار — مصدرٌ واحد تقرأه الواجهة والخادم */
    public const DOMAIN = '@abaadapp.om';

    /** البريد الكامل من اسم المستخدم */
    public static function email(string $username): string
    {
        return mb_strtolower(trim($username)).self::DOMAIN;
    }

    /**
     * قواعد اسم المستخدم.
     *
     * حروفٌ لاتينية وأرقام ونقطة وشرطة لا غير: البريد معرّف دخولٍ يُملى في
     * الهاتف ويُكتب على ورقة، والعربية فيه تجعله غير قابلٍ للكتابة على أي
     * لوحة مفاتيح. والتفرّد يُفحص على البريد الكامل لا على الاسم.
     */
    public static function usernameRules(): array
    {
        return ['string', 'min:3', 'max:40', 'regex:/^[a-z0-9][a-z0-9._-]*$/i'];
    }

    /** رسائل عربية لقواعد اسم المستخدم */
    public static function messages(): array
    {
        return [
            'login_username.regex' => __('اسم المستخدم بحروف إنجليزية وأرقام ونقطة أو شرطة فقط.'),
            'login_username.min' => __('اسم المستخدم ثلاثة أحرف على الأقل.'),
        ];
    }

    /** الاسم من البريد الكامل — لملء الحقل في شاشة التعديل */
    public static function username(string $email): string
    {
        return (string) str($email)->before('@');
    }

    /** هل هذا العنوان على نطاق أبعاد؟ — الحسابات القديمة خارجه لا تُنقل بلا قصد */
    public static function onDomain(?string $email): bool
    {
        return $email !== null && str_ends_with(mb_strtolower($email), self::DOMAIN);
    }

    /** هل البريد الكامل محجوز؟ (التحقق يجري على الاسم فيُبنى الكامل هنا) */
    public static function taken(string $username, ?int $exceptUserId = null): bool
    {
        return User::where('email', self::email($username))
            ->when($exceptUserId, fn ($q) => $q->where('id', '!=', $exceptUserId))
            ->exists();
    }

    /**
     * كلمة مرورٍ مؤقّتة تُملى في الهاتف.
     *
     * وكانت `'Ab'.random_int(1000, 9999)` — تسعةُ آلاف احتمالٍ لا غير، وشكلٌ
     * معروفٌ يبدأ بـ«Ab». من عرف القاعدة جرّبها كلَّها في دقائق، وهي كلمةُ
     * دخولٍ إلى صندوقٍ ومخزونٍ وأرقام هواتف زبائن.
     *
     * وبلا الحروف الملتبسة (l/1/O/0): تُقرأ من ورقةٍ أو تُسمع في الهاتف،
     * وحرفٌ ملتبسٌ واحد يعني محاولةَ دخولٍ تفشل بلا سبب ظاهر.
     */
    public static function temporaryPassword(int $length = 10): string
    {
        $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $out;
    }

    /** مالك الشركة — أوّل حسابٍ بدور admin فيها */
    public static function owner(Business $business): ?User
    {
        return User::where('business_id', $business->id)->where('role', 'admin')->orderBy('id')->first();
    }

    /**
     * ينشئ حساب المالك ويجهّز وظيفته.
     *
     * الوظيفة تُنشأ معه: الصلاحيات تُشتقّ من الوظيفة، وحسابٌ بلا وظيفةٍ في
     * متجره يظهر في شاشة الموظفين بوظيفةٍ فارغة لا تُعدَّل.
     */
    public static function create(Business $business, string $username, string $password): User
    {
        JobTitle::firstOrCreate(
            ['business_id' => $business->id, 'name' => 'مدير'],
            ['role' => 'admin'],
        );

        return User::create([
            'business_id' => $business->id,
            'name' => $business->owner_name ?: $business->name,
            'email' => self::email($username),
            'phone' => $business->phone,
            'role' => 'admin',
            'job_title' => 'مدير',
            'status' => 'نشط',
            'password' => $password,
        ]);
    }
}
