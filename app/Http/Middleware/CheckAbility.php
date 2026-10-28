<?php

namespace App\Http\Middleware;

use App\Support\Permissions;
use App\Support\SectionActions;
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
        $name = $request->route()?->getName();

        // هيكل اللوحة لا أقسامها — انظر Permissions::SHELL
        if (Permissions::isShell($name)) {
            return $next($request);
        }

        // شاشةٌ يحرسها فعلٌ لا قسم — محادثاتُ واتساب (انظر `Permissions::ACTION_ROUTES`)
        if (($action = Permissions::routeAction($name)) !== null) {
            abort_unless($user->may($action), 403, __('ليس لديك صلاحية لهذا الإجراء.'));

            return $next($request);
        }

        /*
         * ومن دخل اللوحةَ بالمحادثات وحدها لا يبلغ قسمًا آخر فيها — ولو حمله
         * دورُه: الكاشيرُ يحمل «لوحة التحكم» ولا يدخلها (`entersBySections`).
         */
        if (str_starts_with((string) $name, 'admin.') && ! Permissions::entersBySections($user)) {
            abort(403, __('ليس لديك صلاحية الوصول إلى هذه الصفحة.'));
        }

        $section = Permissions::sectionFromRoute($name);
        if (! $user->allows($section)) {
            abort(403, __('ليس لديك صلاحية للوصول إلى قسم «:section».', ['section' => $section]));
        }

        /*
         * والقسمُ يفتح الشاشة ولا يبيح ما فيها: الإضافةُ والتعديلُ والحذفُ
         * والتصديرُ أفعالٌ تُمنح بأسمائها (`SectionActions`). ويُسأل عنها هنا
         * لا في الزرّ — من يرسل الطلب بيده يُردّ كمن يضغطه.
         */
        if (($operation = SectionActions::forRoute($name)) !== null && ! $user->may($operation)) {
            abort(403, __('ليس لديك صلاحية: :action', ['action' => Permissions::actionLabels()[$operation] ?? $operation]));
        }

        return $next($request);
    }
}
