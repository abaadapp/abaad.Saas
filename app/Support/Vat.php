<?php

namespace App\Support;

use App\Models\Product;
use App\Models\Setting;

/**
 * ضريبة القيمة المضافة: هل هي مفعّلة أصلًا، وبأي نسبة.
 *
 * كان المفتاح «تفعيل ضريبة القيمة المضافة» يُحفَظ ولا يقرؤه شيء: يُطفئه من
 * لا ضريبة عليه — ومن يبيع دون حدّ التسجيل في عُمان كذلك — فتبقى الضريبة
 * تُضاف إلى كل فاتورة، وتُقرّ في التقرير الضريبي، ويجبيها من زبائنه وهو غير
 * مخوَّلٍ بجبايتها. وهو خطأٌ يقع على الزبون وعلى الإقرار معًا، لا على الشاشة.
 *
 * فصار موضعًا واحدًا يُسأل قبل كل احتساب.
 */
class Vat
{
    /** مفعّلة ما لم تُطفأ صراحةً — فلا يتغيّر شيء لمن لم يلمس المفتاح */
    public static function enabled(int $businessId): bool
    {
        $value = Setting::where('business_id', $businessId)->where('key', 'vat_enabled')->value('value');

        return $value === null || $value === '1';
    }

    /**
     * السعر المعروض شاملٌ للضريبة؟
     *
     * «شامل» يعني أن ما كُتب على الرفّ هو ما يدفعه الزبون، فتُستخرَج الضريبة
     * منه لا تُضاف إليه. و«غير شامل» تُضاف فوقه — وهو الافتراضيّ.
     */
    public static function inclusive(int $businessId): bool
    {
        /*
         * ويرجع إلى افتراضيّ المنصّة كما ترجع النسبة.
         *
         * كان يُقرأ صفُّ المتجر وحده، فمقبض «طريقة احتساب الضريبة» في إعدادات
         * المنصّة يُحفظ ولا يقرؤه شيء — والشاشة نفسها تعد فوقه: «تُطبَّق على
         * متجرٍ لم يضبط ضريبته». وعده صادقٌ في النسبة كاذبٌ في الطريقة، وهما
         * في بطاقةٍ واحدة تحت عنوانٍ واحد.
         */
        $value = Setting::where('business_id', $businessId)->where('key', 'tax_mode')->value('value')
            ?? Setting::whereNull('business_id')->where('key', 'tax_mode')->value('value');

        return $value === 'inclusive';
    }

    /** نسبة المتجر — صفرٌ إن كانت الضريبة مطفأة */
    public static function rate(int $businessId): float
    {
        if (! self::enabled($businessId)) {
            return 0.0;
        }

        $value = Setting::where('business_id', $businessId)->where('key', 'vat_rate')->value('value')
            ?? Setting::whereNull('business_id')->where('key', 'vat_rate')->value('value');

        return max(0.0, (float) ($value ?? 5));
    }

    /** نسبة صنفٍ بعينه — نسبته الخاصّة إن كانت له، وإلا نسبة المتجر */
    public static function rateFor(?Product $product, int $businessId): float
    {
        $default = self::rate($businessId);

        return $product ? $product->taxRate($default) : $default;
    }
}
