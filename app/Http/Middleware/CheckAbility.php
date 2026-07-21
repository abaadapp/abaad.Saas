<?php

namespace App\Http\Middleware;

use App\Support\Permissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * يتحقق أن المستخدم يملك صلاحية القسم المستنتج من اسم المسار الحالي.
 */
class CheckAbility
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }
        $section = Permissions::sectionFromRoute($request->route()?->getName());
        if (! $user->allows($section)) {
            abort(403, __('ليس لديك صلاحية للوصول إلى قسم «:section».', ['section' => $section]));
        }

        return $next($request);
    }
}
