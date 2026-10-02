<?php

namespace App\Support\Store;

/**
 * اسمُ المشتري والمستلِم والعنوانُ بالإنجليزيّة وحدها — لمتاجر القائمة.
 *
 * ═══ ولمَ في الخادم ═══
 *
 * الشاشةُ تكتب الحقولَ من اليسار (`dir="ltr"`)، لكنّ الحمولةَ يكتبها من
 * شاء. فالقاعدةُ هنا، ويقرؤها `WebCheckout::validated`.
 *
 * ولا تُسأل لغةُ الصفحة: زبونٌ يتصفّح بالعربيّة يكتب هذه الثلاثة
 * بالإنجليزيّة. والهاتفُ والمنطقةُ والموعدُ ونصُّ الكرت لا تُمسّ.
 *
 * انظر `storefront.ribbon_english_checkout_businesses`.
 */
final class EnglishCheckout
{
    /**
     * الاسم: حروفٌ لاتينيّة ومسافاتٌ والفاصلةُ العليا والشرطةُ والنقطة.
     *
     * والفاصلةُ العليا بشكليها: لوحةُ مفاتيح الهاتف تُبدّل `'` بـ`’` من
     * نفسها، فمن كتب O'Neil على هاتفه لا يُردّ بحرفٍ لم يكتبه.
     */
    public const NAME = "/^[A-Za-z .'’\\-]+$/u";

    /** العنوان: ما سبق وأرقامٌ وعلاماتُ العنوان المعتادة */
    public const ADDRESS = "/^[A-Za-z0-9 ,.'’\\-\\/#()]+$/u";

    public static function on(int $businessId): bool
    {
        $list = array_map('intval', (array) config('storefront.ribbon_english_checkout_businesses', []));

        return in_array($businessId, $list, true);
    }

    /**
     * قاعدةُ حقلٍ لمتجرٍ في القائمة — وما سواه بلا زيادة.
     *
     * @param  array<int, string>|null  $rules  قواعدُ الحقل القائمة؛ `null` حقلٌ مُطفأ
     * @return array<int, string>|null
     */
    public static function rules(int $businessId, ?array $rules, string $pattern): ?array
    {
        if ($rules === null || ! self::on($businessId)) {
            return $rules;
        }

        return array_merge($rules, ['regex:'.$pattern]);
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'name.regex' => __('اكتب الاسم بالإنجليزية فقط.'),
            'recipient_name.regex' => __('اكتب اسم المستلم بالإنجليزية فقط.'),
            'address.regex' => __('اكتب العنوان بالإنجليزية فقط.'),
        ];
    }
}
