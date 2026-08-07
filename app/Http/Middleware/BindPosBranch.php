<?php

namespace App\Http\Middleware;

use App\Support\PosTerminal;
use Closure;
use Illuminate\Http\Request;

/**
 * فرع نقطة البيع يأتي من الجهاز، لا من الجلسة.
 *
 * كان الكاشير يرث الفرع الذي اختاره المدير في مبدّل الفروع — أو «كل الفروع»
 * فيسقط على أوّل فرعٍ في القائمة. وهذا يعني أن تبديلًا في تبويبٍ آخر (أو جلسةً
 * قديمة عالقة) ينقل مبيعات فرعٍ إلى فرعٍ آخر بلا إنذار، ولا يُكتشف إلا عند
 * جرد آخر الشهر.
 *
 * والضبط في كل طلب لا عند الدخول وحده: الجلسة واحدة بين تبويب الإدارة وتبويب
 * الصندوق، فما يكتبه ذاك يقرأه هذا بعد لحظة.
 *
 * ولوحة الإدارة تبقى على حالها — «كل الفروع» عرضٌ مشروع فيها. الحظر هنا على
 * البيع وحده: البيعة تقع في فرعٍ بعينه أو لا تقع.
 */
class BindPosBranch
{
    public function handle(Request $request, Closure $next)
    {
        if ($branchId = PosTerminal::branchId()) {
            session(['current_branch' => $branchId]);
        }

        return $next($request);
    }
}
