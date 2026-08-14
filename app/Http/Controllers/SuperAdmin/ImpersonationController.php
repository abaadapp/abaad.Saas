<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use App\Support\Activity;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * دخول مدير المنصة إلى حساب تاجر ليرى ما يراه.
 *
 * كان `RequiresBusiness` يقول لمدير المنصة حرفيًّا: «ادخل إليه من صفحة
 * الشركات» — وهذا الفعل لم يكن موجودًا. رسالةٌ تُرشد إلى بابٍ لم يُبنَ.
 * فحين يتصل تاجرٌ يقول «الشاشة عندي فارغة» لم يكن أمام الدعم إلا أن يطلب
 * صورة.
 *
 * وثلاثة قيود تجعله أداة دعمٍ لا بابًا خلفيًّا:
 *  ١) الدخول يُسجَّل في سجلّ النشاط باسم من دخل ومتى، والخروج كذلك.
 *  ٢) الجلسة تحمل `impersonator_id` — فالواجهة تعرض شريطًا أحمر لا يُخفى،
 *     ولا يظنّ أحد أنّه في حسابه.
 *  ٣) لا يُنتحل مدير منصةٍ آخر: انتحالُ من يملك مثل صلاحياتك ليس دعمًا.
 */
class ImpersonationController extends Controller
{
    public function start(Request $request, $businessId)
    {
        $business = Business::findOrFail($businessId);

        // صاحب الحساب أوّلًا، وإلا أيّ مستخدمٍ فيه — متجرٌ بلا مستخدم لا يُدخل
        $target = User::where('business_id', $business->id)
            ->orderByRaw("CASE WHEN role = 'admin' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first();

        if (! $target) {
            return back()->with('toast', [
                'msg' => __('لا يوجد مستخدم في هذا المتجر للدخول به.'),
                'type' => 'warning',
            ]);
        }

        if ($target->isSuperAdmin()) {
            abort(403);
        }

        Activity::log('login', 'دخل كتاجر: '.$business->name, [
            'business_id' => null,
            'subject_id' => $business->id,
        ]);

        $impersonator = $request->user()->id;
        Auth::login($target);
        $request->session()->put('impersonator_id', $impersonator);

        /*
         * والخروجُ يعيده إلى حيث دخل، لا إلى قائمة الشركات دائمًا.
         *
         * قسم الديمو يستثني متاجره من تلك القائمة عمدًا — فمن دخل متجرًا
         * تجريبيًّا ثم خرج كان يقف أمام قائمةٍ لا يجد فيها ما خرج منه للتوّ.
         *
         * ويُقبل المرجع إن كان داخل لوحة المنصة وحدها: القيمة تأتي من ترويسة
         * المتصفّح، وإعادةُ التوجيه إلى ما فيها بلا فحصٍ تُخرج المستخدم إلى
         * أي موقع.
         */
        $back = (string) url()->previous();
        $request->session()->put(
            'impersonate_return',
            str_starts_with($back, url('/super-admin')) ? $back : route('super-admin.businesses.index'),
        );

        return redirect(Permissions::homeFor($target))->with('toast', [
            'msg' => __('تدخل الآن باسم :name', ['name' => $target->name]),
            'type' => 'info',
        ]);
    }

    public function stop(Request $request)
    {
        $id = $request->session()->pull('impersonator_id');
        $return = (string) $request->session()->pull('impersonate_return');
        $admin = $id ? User::find($id) : null;

        if (! $admin || ! $admin->isSuperAdmin()) {
            Auth::logout();

            return redirect()->route('login');
        }

        Auth::login($admin);
        Activity::log('logout', 'خرج من حساب التاجر', ['business_id' => null]);

        return redirect($return ?: route('super-admin.businesses.index'))->with('toast', [
            'msg' => __('عدتَ إلى لوحة المنصة'),
            'type' => 'success',
        ]);
    }
}
