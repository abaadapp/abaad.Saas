<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Support\Demo;
use Closure;
use Illuminate\Http\Request;

/**
 * يضبط لغة الواجهة لكل طلب، بأولوية من الأخصّ إلى الأعمّ:
 *
 *   1. الجلسة        — اختيار هذه الجلسة (يشمل الزائر قبل الدخول)
 *   2. المستخدم      — تفضيله الشخصي الباقي بعد الخروج
 *   3. إعداد النشاط  — افتراضي المتجر لمن لم يختر بعد
 *   4. العربية
 *
 * الخطوة 2 هي الجديدة: بدونها كان الكاشير والمالك يتنازعان إعدادًا واحدًا
 * مشتركًا، فيغيّر كلٌّ منهما لغة الآخر.
 */
class SetLocale
{
    public const SUPPORTED = ['ar', 'en'];

    public function handle(Request $request, Closure $next)
    {
        $locale = session('locale');
        $user = auth()->user();

        if (! in_array($locale, self::SUPPORTED, true) && $user) {
            $locale = $user->locale;
        }

        if (! in_array($locale, self::SUPPORTED, true) && $user && $user->business_id) {
            $locale = Setting::where('business_id', $user->business_id)
                ->where('key', 'locale')->value('value');
        }

        if (! in_array($locale, self::SUPPORTED, true)) {
            $locale = 'ar';
        }

        app()->setLocale($locale);

        // Carbon لا يتبع لغة التطبيق تلقائيًا — بدون هذا تبقى «منذ 19 دقيقة»
        // وأسماء الشهور عربية في الواجهة الإنجليزية.
        \Carbon\Carbon::setLocale($locale);

        return $next($request);
    }
}
