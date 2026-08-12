<?php

namespace App\Http\Middleware;

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
 * ويُستثنى انتحالُ المنصة: مدير المنصة يدخل حساب تاجرٍ معطَّل ليرى ما يشكو
 * منه — ومنعُه هناك يمنع الإصلاح نفسه.
 */
class CheckTenantStatus
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($request->session()->has('impersonator_id')) {
            return $next($request);
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
}
