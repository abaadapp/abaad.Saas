<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Support\Activity;
use App\Support\Demo;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CouponController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    public function store(Request $request)
    {
        $bid = $this->bid();

        /*
         * الكود يُرفع إلى الأحرف الكبيرة **قبل** الفحص لا بعده.
         *
         * كان يُفحص خامًا ويُحفظ مرفوعًا، والصندوق يبحث بـUPPER(code): فمن
         * كتب `save10` بعد `SAVE10` مرّ الفحص وأُنشئ كودان يطابقان الكود
         * نفسه عند الدفع — و`first()` تختار أحدهما بلا قاعدة، فقد يقع
         * الاختيار على الموقوف أو على المنتهي.
         */
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('coupons', 'code')->where('business_id', $bid)],
            'type' => ['required', 'in:نسبة,مبلغ'],
            /*
             * نسبةٌ فوق المئة لا معنى لها.
             *
             * `discountFor` تقصّها عند المجموع فلا تصير الفاتورة سالبة —
             * لكنّ التاجر يكتب «١٥٠٪» ويظنّه يعمل، ويقرؤه في القائمة كذلك.
             * حدٌّ يُقصّ بصمت وعدٌ مكسور.
             */
            'value' => ['required', 'numeric', 'min:0', 'max:'.($request->input('type') === 'نسبة' ? 100 : 1000000)],
            'min_order' => ['nullable', 'numeric', 'min:0'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            /*
             * والحدُّ لكلّ زبون حدٌّ آخر، لا صياغةٌ أخرى للأوّل.
             *
             * «مرّتان لكلّ زبون» بلا حدٍّ إجماليّ: أحمدُ مرّتان ومحمّدٌ مرّتان
             * ولا ينتهي الكود. وبحدٍّ إجماليّ معه: يُطبَّقان معًا — الكودُ
             * يُغلق حين يبلغ الإجماليُّ حدَّه، والزبونُ يُردّ حين يبلغ حدَّه هو.
             *
             * والفراغُ يعني بلا حدٍّ لكلّ زبون — وهو حالُ كوبونات اليوم كلِّها.
             */
            'per_customer_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            // كوبونٌ ينتهي أمس ميّتٌ يوم يُنشأ: يُعرض في القائمة، ويُكتب على
            // اللافتة، ويُردّ عند الصندوق — ولا شيء قاله عند الحفظ
            'expires_at' => ['nullable', 'date', 'after_or_equal:today'],
        ], [
            'expires_at.after_or_equal' => __('تاريخ الانتهاء مضى — الكوبون لن يعمل ولا مرّة.'),
        ]);
        Coupon::create([
            'business_id' => $bid,
            'code' => strtoupper($data['code']),
            'type' => $data['type'],
            'value' => $data['value'],
            'min_order' => $data['min_order'] ?? 0,
            'max_uses' => $data['max_uses'] ?? null,
            'per_customer_limit' => $data['per_customer_limit'] ?? null,
            /*
             * ولحظةُ التفعيل تُكتب مع الحدّ — لا تُستنتج من تاريخ نشرٍ.
             *
             * كودٌ جديدٌ ضُبط حدُّه عند إنشائه لا استعمالاتَ قبله، فالتاريخُ
             * هو ميلادُه نفسُه. والفائدةُ في الأكواد القائمة (انظر `limits`).
             */
            'per_customer_since' => isset($data['per_customer_limit']) ? now() : null,
            'expires_at' => $data['expires_at'] ?? null,
            'active' => true,
        ]);
        Activity::log('created', 'أنشأ كوبون خصم: '.strtoupper($data['code']));

        return back()->with('toast', ['msg' => __('تم إنشاء الكوبون'), 'type' => 'success']);
    }

    /**
     * تعديلُ حدَّي الكوبون — الإجماليّ والذي لكلّ زبون.
     *
     * ═══ ولمَ بابٌ لهذين وحدَهما ═══
     *
     * الكودُ نفسُه ونوعُه وقيمتُه لا تُعدَّل: زبائنٌ قرؤوا «SAVE10 خصم ١٠٪»
     * على لافتةٍ ووصلتهم رسالة. وتبديلُ معناه تحت أقدامهم وعدٌ مكسور — من
     * أراد غيرَه أنشأ كودًا غيرَه.
     *
     * أمّا الحدودُ فسياسةُ التاجر في متجره: يفتح كودَه القديم ويقول «مرّتان
     * لكلّ زبون» — وهذا هو البابُ الذي يجعل القاعدة الثانية ممكنةً أصلًا:
     * العدُّ يبدأ من لحظة قوله، لا من استعمالاتٍ مضت قبل أن يقول.
     */
    public function limits(Request $request, $id)
    {
        $coupon = Coupon::where('business_id', $this->bid())->findOrFail($id);

        $data = $request->validate([
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $limit = $data['per_customer_limit'] ?? null;

        /*
         * ═══ ولحظةُ التفعيل تُكتب مرّةً ولا تُمحى ═══
         *
         * مَن أطفأ الحدَّ ثمّ أعاده لا تُصفَّر استعمالاتُ زبائنه: وإلّا كان
         * الإطفاءُ والإشعالُ مقبضًا يُفتح به الكودُ من جديد كلَّ يوم — «مرّتان
         * لكلّ زبون» تصير مرّتين في كلّ ضغطتين.
         *
         * فالتاريخُ يُكتب حين لا يكون، ويبقى بعد ذلك على حاله: إطفاءً
         * وإشعالًا ورفعًا للحدّ وخفضًا.
         */
        $since = $coupon->per_customer_since;

        if ($limit !== null && $since === null) {
            $since = now();
        }

        $coupon->update([
            'max_uses' => $data['max_uses'] ?? null,
            'per_customer_limit' => $limit,
            'per_customer_since' => $since,
        ]);

        Activity::log('updated', 'عدّل حدود الكوبون: '.$coupon->code
            .' — إجمالًا: '.($data['max_uses'] ?? 'بلا حدّ')
            .' · لكل زبون: '.($limit ?? 'بلا حدّ'));

        return back()->with('toast', ['msg' => __('تم تحديث حدود الكوبون'), 'type' => 'success']);
    }

    public function toggle($id)
    {
        $coupon = Coupon::where('business_id', $this->bid())->findOrFail($id);
        $coupon->update(['active' => ! $coupon->active]);

        return back()->with('toast', ['msg' => $coupon->active ? __('تم تفعيل الكوبون') : __('تم إيقاف الكوبون'), 'type' => $coupon->active ? 'success' : 'warning']);
    }

    public function destroy($id)
    {
        $coupon = Coupon::where('business_id', $this->bid())->findOrFail($id);
        $code = $coupon->code;
        $coupon->delete();
        Activity::log('deleted', 'حذف الكوبون: '.$code);

        return back()->with('toast', ['msg' => __('تم حذف الكوبون'), 'type' => 'warning']);
    }
}
