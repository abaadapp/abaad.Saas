<?php

namespace App\Support;

use App\Models\Product;
use App\Models\Setting;
use Illuminate\Support\Carbon;

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
     * آخرُ يومٍ دخل في إقرارٍ قُدِّم — وما قبله مُقفل.
     *
     * الإقرارُ ورقةٌ تُسلَّم إلى جهةٍ حكوميّة. وما بعد تسليمها لا يُعاد كتابتُه
     * في الدفتر بلا مستندٍ يقول إنّ شيئًا تغيّر — وإلّا فتح التاجر تقريرَه
     * بعد شهرين فوجد رقمًا غير الذي قدّمه، ولا شيء يقول لماذا.
     *
     * ويُحفظ يومًا لا فترة: «الربع الأوّل» يعني شهورًا مختلفة عند من تبدأ
     * سنتُه المالية في يوليو، و«٣١ مارس» لا تحتمل قراءتين.
     *
     * وفارغةً يعني أنّ التاجر لم يقدّم شيئًا بعد — فلا يُقفل عليه شيء.
     */
    public static function filedThrough(int $businessId): ?Carbon
    {
        $value = Setting::where('business_id', $businessId)->where('key', 'vat_filed_through')->value('value');

        return $value ? Carbon::parse($value)->endOfDay() : null;
    }

    /** هل يقع هذا التاريخ في فترةٍ قُدِّم إقرارُها؟ */
    public static function isFiled(int $businessId, mixed $date): bool
    {
        $filed = self::filedThrough($businessId);

        return $filed !== null && $date !== null && Carbon::parse($date)->lte($filed);
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

    /**
     * نسبة صنفٍ بعينه — نسبته الخاصّة إن كانت له، وإلا نسبة المتجر.
     *
     * والإطفاء يسبق الاثنتين: `rate()` تُرجع صفرًا للمتجر المطفأ، لكنّ
     * `taxRate()` لا تقرأ ذلك الصفر أصلًا حين يحمل الصنفُ نسبةً مكتوبة —
     * فيبقى يُضرَّب بضريبته في متجرٍ أطفأ الضريبة كلَّها. وهو المنطق نفسه
     * الذي حُرِس في `PosController::taxFor` بحارسٍ مستقلّ، وبقي هنا مفتوحًا:
     * فحصٌ في موضعٍ وغيابُه في موضع يعني أن أوّل من ينادي هذه يقع فيه.
     */
    public static function rateFor(?Product $product, int $businessId): float
    {
        if (! self::enabled($businessId)) {
            return 0.0;
        }

        $default = self::rate($businessId);

        return $product ? $product->taxRate($default) : $default;
    }
}
