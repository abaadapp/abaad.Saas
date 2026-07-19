<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * التحقق من دور المستخدم. الاستخدام: ->middleware('role:super_admin')
     * أو role:admin,manager. مدير المنصة (super_admin) يُسمح له بكل شيء.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }
        if ($user->role === 'super_admin' || in_array($user->role, $roles, true)) {
            return $next($request);
        }
        abort(403, 'ليس لديك صلاحية الوصول إلى هذه الصفحة.');
    }
}
