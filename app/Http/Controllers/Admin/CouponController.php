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
            'expires_at' => $data['expires_at'] ?? null,
            'active' => true,
        ]);
        Activity::log('created', 'أنشأ كوبون خصم: '.strtoupper($data['code']));

        return back()->with('toast', ['msg' => __('تم إنشاء الكوبون'), 'type' => 'success']);
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
