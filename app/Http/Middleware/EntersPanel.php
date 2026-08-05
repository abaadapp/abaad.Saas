<?php

namespace App\Http\Middleware;

use App\Support\Permissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * حارس باب لوحة النشاط.
 *
 * كان الباب محروسًا بقائمة أدوار مكتوبة في المسار (`role:admin,manager,…`).
 * ومنذ صارت الصلاحيات تُخصَّص يدويًّا لكل موظف، صار ذلك يمنع كاشيرًا مُنح
 * صلاحية المخزون عند الباب — فلا تُقرأ صلاحيته أصلًا، وتصير الميزة تُحفَظ
 * في القاعدة ولا تعمل.
 *
 * فالباب يسأل الآن Permissions::entersPanel: الدور أو صلاحيةٌ ممنوحة. وما
 * وراء الباب يبقى محروسًا بحارس القسم (CheckAbility)، فالدخول لا يعني
 * الوصول إلى كل شيء.
 */
class EntersPanel
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! Permissions::entersPanel($user)) {
            abort(403, __('ليس لديك صلاحية الوصول إلى هذه الصفحة.'));
        }

        return $next($request);
    }
}
