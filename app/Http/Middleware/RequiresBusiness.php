<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * لوحة النشاط ونقطة البيع تخصّان متجرًا بعينه — لا تُفتحان بلا واحد.
 *
 * مدير المنصة لا يملك business_id، وCheckRole يمرّره إلى كل شيء بينما
 * مسارات /pos لا تفحص الدور أصلًا. فكانت النتيجة عطلين معًا:
 *
 * 1) شاشة بيضاء: HandleInertiaRequests لا يرسل `context` بلا business_id،
 *    والصفحات تقرأ context!.currency فتنهار قبل أن تُركَّب الواجهة.
 * 2) وهو الأخطر — Demo::bid() ترجع أول نشاط لمن لا يملك واحدًا، فالصفحات
 *    التي تصمد كانت تعرض بيانات متجر آخر بلا أن يطلبها أحد.
 *
 * الحلّ عند الباب لا في كل صفحة: من لا متجر له يُعاد إلى لوحته.
 */
class RequiresBusiness
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! $user->business_id) {
            if ($user->role === 'super_admin') {
                return redirect()->route('super-admin.dashboard')->with('toast', [
                    'msg' => __('لوحة النشاط تخصّ متجرًا بعينه — ادخل إليه من صفحة الشركات.'),
                    'type' => 'info',
                ]);
            }

            abort(403, __('حسابك غير مرتبط بأي متجر.'));
        }

        return $next($request);
    }
}
