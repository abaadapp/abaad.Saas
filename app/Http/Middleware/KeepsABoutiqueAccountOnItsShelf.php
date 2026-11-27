<?php

namespace App\Http\Middleware;

use App\Support\BoutiqueAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * حسابُ صاحب البوتيك لا يبلغ إلا أبوابَ أصناف بوتيكه — على كلّ مسارٍ يطلب الدخول.
 *
 * على مجموعة `web` كلّها لا على مجموعةٍ بعينها: لوحةُ النشاط ونقطةُ البيع
 * والتصدير والطباعة وكلُّ مجموعةٍ تُضاف غدًا. وما لا يطلب الدخولَ (الموقع
 * العامّ، وصفحةُ الدخول) لا يُسأل عنه هنا.
 *
 * وصنفٌ ليس من بوتيكه يُردّ بـ٤٠٤ لا ٤٠٣: لا يُقال له إنّ الرقمَ موجود.
 * والقاعدةُ كلُّها في `BoutiqueAccount`.
 */
class KeepsABoutiqueAccountOnItsShelf
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $route = $request->route();

        if (! BoutiqueAccount::bound($user) || $route === null || ! $this->asksForLogin($route->gatherMiddleware())) {
            return $next($request);
        }

        $name = $route->getName();

        if (BoutiqueAccount::opens($user, $name, $route->parameter('id'))) {
            return $next($request);
        }

        abort(in_array($name, BoutiqueAccount::PRODUCT_ROUTES, true) ? 404 : 403,
            __('هذا الحسابُ لأصناف بوتيكه وحدها.'));
    }

    /** @param  array<int, mixed>  $middleware */
    private function asksForLogin(array $middleware): bool
    {
        foreach ($middleware as $m) {
            if (is_string($m) && ($m === 'auth' || str_starts_with($m, 'auth:'))) {
                return true;
            }
        }

        return false;
    }
}
