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

        $reason = Tenancy::blockReason($user);
        if (! $reason) {
            return $next($request);
        }

        $message = Tenancy::message($reason);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'blocked' => $reason, 'message' => $message], 403);
        }

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
