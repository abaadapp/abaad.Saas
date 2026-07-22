<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * يوحّد فواصل المبالغ في كل الطلبات: يقبل الفاصلة (,) والنقطة (.) والأرقام العربية
 * وفواصل الآلاف، ثم يحوّلها إلى صيغة رقمية قياسية قبل أن تصلها طبقة التحقق.
 * يطبَّق فقط على أسماء الحقول المالية المعروفة حتى لا يمسّ أي حقل نصّي آخر.
 */
class NormalizeMoneyInput
{
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
            if (is_string($value) && in_array($key, $this->fields, true)) {
                $value = self::normalize($value);
            }
        });
        $request->merge($input);

        return $next($request);
    }

    /** يحوّل قيمة نصية بأي فاصل إلى رقم عشري بنقطة. يُعيد الأصل إن لم تكن رقمًا. */
    public static function normalize(string $v): string
    {
        $orig = $v;

        // أرقام عربية/فارسية → إنجليزية
        $v = strtr($v, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $v = trim($v);
        if ($v === '') {
            return $v;
        }

        // فاصلة عشرية عربية ٫ → نقطة، وحذف فواصل الآلاف العربية والمسافات
        $v = str_replace('٫', '.', $v);
        $v = str_replace(['٬', '،', ' ', "\xC2\xA0", "'"], '', $v);

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
            $v = implode('', $parts) . '.' . $dec;
        }

        // يجب أن يكون رقمًا صالحًا الآن، وإلا نُعيد الأصل ليتكفّل التحقق بالخطأ
        return preg_match('/^-?\d+(\.\d+)?$/', $v) ? $v : $orig;
    }
}
