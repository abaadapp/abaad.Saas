<?php

namespace App\Http\Middleware;

use App\Support\Numerals;
use Closure;
use Illuminate\Http\Request;

/**
 * الأرقام تصل إنجليزيّةً مهما كُتبت.
 *
 * وعمَلان في مَمرٍّ واحد، ولكلٍّ مداه:
 *
 * **الأوّل يعمّ**: كلُّ قيمةٍ في الطلب تُحوَّل أرقامُها إلى اللاتينية (انظر
 * `Numerals`). لا قائمةَ أسماءٍ هنا: الكميّةُ رقم، والهاتفُ رقم، ونسبةُ
 * الضريبة رقم، ورقمُ التسجيل رقم — وقائمةٌ تُكتب باليد تنسى التاليَ دائمًا.
 * وكانت تنساه فعلًا: عشرون اسمًا ماليًّا تُصحَّح، وما عداها يُردّ بـ«يجب أن
 * يكون رقمًا» على رقمٍ مكتوبٍ صحيحًا بلوحةٍ عربية.
 *
 * **والثاني يخصّ المال وحده**: فواصلُ الآلاف والعشريّات — «1,234.5» و
 * «1.234,5» و«1 234» — تُردّ إلى صيغةٍ واحدة. ولا يُعمَّم: «9123 4567» هاتفٌ
 * لا رقمٌ بفاصل آلاف، ولصقُه في حقلٍ عامّ كان سيصير «91234567» بلا أن يقول.
 *
 * وكلمةُ السرّ خارج الاثنين: تبديلُ حرفٍ فيها يعني حسابًا لا يُفتح بعدها،
 * والتبديل لا يُرى — انظر `untouched`.
 */
class NormalizeNumbers
{
    /**
     * ما لا يُمسّ حرفٌ منه — ولو بدا رقمًا.
     *
     * كلمةُ سرٍّ فيها «٥» تُحفَظ بـ«5» عند الإنشاء، فإذا كتبها صاحبُها من
     * لوحةٍ إنجليزية فُتح له الحساب، ومن عربيةٍ فُتح كذلك — إلى أن يمرّ
     * الحقلُ يومًا بمسارٍ لا يمرّ بهذا الوسيط. وحسابٌ لا يُفتح بلا سببٍ ظاهر
     * أسوأ من كلمة سرٍّ بأرقامٍ عربية.
     */
    private const UNTOUCHED = ['password', 'secret', 'token'];

    /** أسماء الحقول المالية (على أي عمق، وبأي فهرس مثل items.*.price) */
    private array $fields = [
        'price', 'cost', 'amount', 'discount', 'tax', 'total', 'subtotal',
        'delivery_fee', 'opening_balance', 'monthly', 'yearly', 'monthly_price',
        'yearly_price', 'free_threshold', 'fee', 'paid', 'unit_price', 'salary',
        'monthly_target', 'balance', 'min_order',
    ];

    public function handle(Request $request, Closure $next)
    {
        $input = $request->all();

        array_walk_recursive($input, function (&$value, $key) {
            if (! is_string($value) || $value === '' || self::untouched((string) $key)) {
                return;
            }

            $value = Numerals::toAscii($value);

            if (in_array($key, $this->fields, true)) {
                $value = self::normalize($value);
            }
        });

        $request->merge($input);

        return $next($request);
    }

    /** هل هذا الحقل ممّا لا يُمسّ؟ — بالتضمين لا بالمطابقة: `password_confirmation` منه */
    public static function untouched(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::UNTOUCHED as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** يحوّل قيمة نصية بأي فاصل إلى رقم عشري بنقطة. يُعيد الأصل إن لم تكن رقمًا. */
    public static function normalize(string $v): string
    {
        $orig = $v;

        // والأرقام من مصدرٍ واحد لا خريطةٍ ثانية هنا: خريطتان تفترقان يومًا
        $v = trim(Numerals::toAscii($v));
        if ($v === '') {
            return $v;
        }

        /*
         * والفاصلة العربية «،» تُقرأ فاصلةً لا تُحذف.
         *
         * كانت تُمحى مع فواصل الآلاف، فيكتب التاجر «4،5» على لوحةٍ عربية —
         * وهي الفاصلة التي تُخرجها لوحتُه حيث تُخرج الإنجليزيةُ «,» — فتصير
         * «45». عشرةُ أضعاف الثمن، بلا خطأٍ ولا رسالة، ولا يظهر إلّا في
         * فاتورةٍ أو أمر شراء.
         *
         * وتُردّ إلى «,» لا إلى «.» مباشرةً: ما بعدها يحكم عليها بالقاعدة
         * نفسها التي تحكم أختها اللاتينية — واحدةٌ عشريّة، وأكثرُ فواصلُ آلاف.
         */
        $v = str_replace('،', ',', $v);

        // فواصل الآلاف والمسافات تُحذف — والعشريّة صارت نقطةً في `Numerals`
        $v = str_replace([' ', "\xC2\xA0", "'"], '', $v);

        $hasDot = str_contains($v, '.');
        $hasComma = str_contains($v, ',');

        if ($hasDot && $hasComma) {
            // الأخير هو الفاصل العشري، والآخر فاصل آلاف
            if (strrpos($v, ',') > strrpos($v, '.')) {
                $v = str_replace('.', '', $v);
                $v = str_replace(',', '.', $v);
            } else {
                $v = str_replace(',', '', $v);
            }
        } elseif ($hasComma) {
            // فاصلة واحدة = عشرية، أكثر = فواصل آلاف
            $v = substr_count($v, ',') === 1 ? str_replace(',', '.', $v) : str_replace(',', '', $v);
        } elseif ($hasDot && substr_count($v, '.') > 1) {
            // نقاط متعددة = فواصل آلاف بالنقطة، آخرها العشري
            $parts = explode('.', $v);
            $dec = array_pop($parts);
            $v = implode('', $parts).'.'.$dec;
        }

        // يجب أن يكون رقمًا صالحًا الآن، وإلا نُعيد الأصل ليتكفّل التحقق بالخطأ
        return preg_match('/^-?\d+(\.\d+)?$/', $v) ? $v : $orig;
    }
}
