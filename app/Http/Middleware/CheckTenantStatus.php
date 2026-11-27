<?php

namespace App\Http\Middleware;

use App\Support\Activity;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * لا يعمل موقوفٌ ولا معطَّلٌ ولا منتهي الاشتراك.
 *
 * الفحص عند الباب لا في كل شاشة: الجلسةُ تُنهى ويُعاد صاحبها إلى الدخول
 * برسالةٍ تقول السبب. وإنهاء الجلسة مقصود — إبقاؤها مفتوحة يعني أن الإيقاف
 * لا يسري إلا بعد أن يخرج المستخدم بنفسه، وهو ما لن يفعله.
 *
 * ويُستثنى انتحالُ المنصة من الطرد: مدير المنصة يدخل حساب تاجرٍ معطَّل ليرى
 * ما يشكو منه — ومنعُه هناك يمنع الإصلاح نفسه. لكنّه يدخل **قارئًا**: انظر
 * `support`.
 */
class CheckTenantStatus
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($request->session()->has('impersonator_id')) {
            return $this->support($request, $next, $user);
        }

        /*
         * الصيانة تسبق كلّ فحص: هي حالة النظام لا حالة الحساب.
         *
         * ولا تُنهي الجلسة — الوقفة مؤقّتة، وإخراجُ الجميع يعني أن كلّ تاجر
         * يعود ليكتب كلمة سرّه بعد ترقيةٍ استغرقت دقيقتين. و503 مقصود:
         * يقول للمتصفّح وللمراقبة «موقوف مؤقّتًا» لا «تعطّل».
         */
        if ($user && ! $user->isSuperAdmin() && Tenancy::maintenance()) {
            $message = __('النظام في وضع الصيانة مؤقّتًا. سنعود بعد قليل.');

            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'blocked' => 'maintenance', 'message' => $message], 503);
            }

            return \Inertia\Inertia::render('Auth/Maintenance', ['message' => $message])
                ->toResponse($request)->setStatusCode(503);
        }

        $reason = Tenancy::blockReason($user);
        if (! $reason) {
            return $next($request);
        }

        $message = Tenancy::message($reason);

        /*
         * منتهي الاشتراك يُحجز ولا يُطرد.
         *
         * كان يُخرَج إلى شاشة الدخول برسالةٍ في حقل البريد، فيقرؤها ويحاول
         * ثانيةً ظنًّا أنه أخطأ كلمة المرور. ثم يتّصل ليسأل «لماذا لا أدخل؟»
         * قبل أن يسأل «كيف أجدّد؟» — وهو السؤال الوحيد الذي يهمّ الطرفين.
         * فصار يدخل ويقف عند صفحةٍ تقول له كم عليه وبمن يتّصل.
         */
        if (! Tenancy::isHard($reason)) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'blocked' => $reason, 'message' => $message], 403);
            }

            return redirect()->route('subscription.expired');
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'blocked' => $reason, 'message' => $message], 403);
        }

        return redirect()->route('login')->withErrors(['email' => $message]);
    }

    /**
     * جلسةُ الدعم داخل حسابِ غيره.
     *
     * ═══ ١) في الحساب الممنوع: يقرأ ولا يكتب ═══
     *
     * كان الانتحالُ يتخطّى هذا الحارسَ كلَّه — فمن دخل متجرًا معطَّلًا أو
     * منتهيَ الاشتراك أو بانتظار السداد كان يبيع فيه ويحفظ ويعدّل، وصاحبُه
     * نفسُه لا يستطيع. فصار يرى كلَّ شيء (`GET`) ولا يُحفظ له شيء: لا يُعاد
     * المتجرُ إلى العمل من الباب الخلفيّ، ولا يُتجاوز قيدُ اشتراكٍ أو مال.
     * والسببُ من `Tenancy::supportViewOnly` — قاعدةِ الشريط الأحمر نفسِها.
     *
     * ═══ ٢) وكلُّ طلبِ كتابةٍ يُقيَّد — بمن فعله حقًّا ═══
     *
     * سجلُّ النشاط يحمل `impersonator_id` على كلّ سطرٍ يُكتب أثناء الانتحال
     * (`Activity::log`). لكنّ أفعالًا كثيرة لا تكتب سطرًا، والفاتورةُ نفسُها
     * تحمل اسمَ الموظّف وحده — فلا يُعرف بعدها أنّ الدعمَ هو من أصدرها.
     * فكلُّ طلبٍ غيرِ `GET` يُقيَّد هنا: الطريقةُ واسمُ المسار والعنوانُ
     * والنتيجة، **لا محتواه** — لا كلمةَ مرور ولا رمزَ ولا نصَّ نموذج.
     */
    private function support(Request $request, Closure $next, $user): Response
    {
        $writes = ! $request->isMethodSafe();
        $limit = $writes ? Tenancy::supportViewOnly($user) : null;

        if ($limit !== null) {
            $message = __('للعرض فقط أثناء الدعم — :reason. لا يُحفظ هنا شيء.', ['reason' => $limit]);
            $response = $request->expectsJson()
                ? response()->json(['ok' => false, 'blocked' => 'support_view_only', 'message' => $message], 403)
                : back()->with('toast', ['msg' => $message, 'type' => 'warning']);
        } else {
            $response = $next($request);
        }

        if ($writes) {
            Activity::log('support', mb_substr(sprintf(
                'طلب عبر الدعم: %s %s %s → %d%s',
                $request->method(),
                $request->route()?->getName() ?? '-',
                '/'.$request->path(),
                $response->getStatusCode(),
                $limit !== null ? ' (مرفوض: للعرض فقط)' : '',
            ), 0, 255));
        }

        return $response;
    }
}
